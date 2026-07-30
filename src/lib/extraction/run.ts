import Anthropic from "@anthropic-ai/sdk";
import { createSupabaseAdminClient } from "@/lib/supabase/admin";
import { extractionResultSchema, extractionToolSchema, type ExtractionResult } from "./schema";
import { combineConfidence, runValidators } from "./confidence";
import { PROMPT_VERSION, SYSTEM_PROMPT, USER_PROMPT } from "./prompt";

const MODEL = process.env.EXTRACTION_MODEL ?? "claude-sonnet-5";

type ImageMediaType = "image/jpeg" | "image/png" | "image/webp" | "image/gif";

// Runs one extraction over an already-claimed document (processing_status =
// 'extracting'). Always writes an extraction row — raw_output is saved even
// when parsing fails. Throws on failure so the caller can decide about retry.
export async function runExtraction(documentId: string): Promise<void> {
  const admin = createSupabaseAdminClient();
  const startedAt = new Date().toISOString();

  const { data: doc, error: docErr } = await admin
    .from("document")
    .select("id, company_id, processing_status")
    .eq("id", documentId)
    .single();

  if (docErr || !doc) throw new Error(`document not found: ${documentId}`);
  if (doc.processing_status !== "extracting") {
    throw new Error(`document ${documentId} is not claimed (status: ${doc.processing_status})`);
  }

  const { data: file, error: fileErr } = await admin
    .from("document_file")
    .select("storage_path, mime_type")
    .eq("document_id", documentId)
    .order("created_at", { ascending: true })
    .limit(1)
    .single();

  if (fileErr || !file) throw new Error(`document_file not found for ${documentId}`);

  const { data: blob, error: dlErr } = await admin.storage
    .from("iratok")
    .download(file.storage_path);

  if (dlErr || !blob) throw new Error(`storage download failed: ${dlErr?.message}`);

  const base64 = Buffer.from(await blob.arrayBuffer()).toString("base64");

  const contentBlock =
    file.mime_type === "application/pdf"
      ? {
          type: "document" as const,
          source: { type: "base64" as const, media_type: "application/pdf" as const, data: base64 },
        }
      : {
          type: "image" as const,
          source: {
            type: "base64" as const,
            media_type: (file.mime_type ?? "image/jpeg") as ImageMediaType,
            data: base64,
          },
        };

  const anthropic = new Anthropic();
  let response: Anthropic.Message | null = null;
  let parsed: ExtractionResult | null = null;
  let parseError: string | null = null;

  try {
    response = await anthropic.messages.create({
      model: MODEL,
      max_tokens: 2048,
      system: SYSTEM_PROMPT,
      messages: [
        { role: "user", content: [contentBlock, { type: "text", text: USER_PROMPT }] },
      ],
      tools: [
        {
          name: "record_extraction",
          description: "Rogziti az iratbol kinyert iktatasi mezoket.",
          input_schema: extractionToolSchema as Anthropic.Tool.InputSchema,
        },
      ],
      tool_choice: { type: "tool", name: "record_extraction" },
    });

    const toolUse = response.content.find(
      (b): b is Anthropic.ToolUseBlock => b.type === "tool_use"
    );
    if (!toolUse) {
      parseError = "no tool_use block in response";
    } else {
      const result = extractionResultSchema.safeParse(toolUse.input);
      if (result.success) {
        parsed = result.data;
      } else {
        parseError = "schema validation failed: " + result.error.message;
      }
    }
  } catch (err) {
    // API error: record the attempt, then rethrow for the retry logic.
    await admin.from("extraction").insert({
      company_id: doc.company_id,
      document_id: documentId,
      model_name: MODEL,
      prompt_version: PROMPT_VERSION,
      raw_output: null,
      started_at: startedAt,
      finished_at: new Date().toISOString(),
      error: err instanceof Error ? err.message : String(err),
    });
    throw err;
  }

  const validators = parsed ? runValidators(parsed) : {};
  const fieldConfidence = parsed ? combineConfidence(parsed.confidence, validators) : null;

  // The raw model response is saved as-is; parsed_fields is the frozen machine
  // value set that human corrections will be measured against.
  const parsedFields = parsed
    ? {
        partner_name: parsed.partner_name,
        partner_tax_number: parsed.partner_tax_number,
        targy: parsed.targy,
        irat_szama: parsed.irat_szama,
        erkezett_at: parsed.erkezett_at,
        issue_date: parsed.issue_date,
        due_date: parsed.due_date,
        direction: parsed.direction,
        doc_kind: parsed.doc_kind,
        melleklet_db: parsed.melleklet_db,
        net_amount: parsed.net_amount,
        vat_amount: parsed.vat_amount,
        gross_amount: parsed.gross_amount,
        currency: parsed.currency,
        tobb_irat_gyanu: parsed.tobb_irat_gyanu,
      }
    : null;

  const { error: extErr } = await admin.from("extraction").insert({
    company_id: doc.company_id,
    document_id: documentId,
    model_name: MODEL,
    model_version: response?.model ?? null,
    prompt_version: PROMPT_VERSION,
    raw_output: response ? JSON.parse(JSON.stringify(response)) : null,
    parsed_fields: parsedFields,
    field_confidence: fieldConfidence,
    started_at: startedAt,
    finished_at: new Date().toISOString(),
    error: parseError,
  });

  if (extErr) throw new Error(`failed to store extraction: ${extErr.message}`);
  if (!parsed) throw new Error(parseError ?? "extraction produced no result");

  // Pre-fill the document for the review screen. Machine values live in
  // extraction.parsed_fields; these columns are the working copy the human
  // will approve or correct.
  const { data: partner } = parsed.partner_tax_number
    ? await admin
        .from("partner")
        .select("id")
        .eq("company_id", doc.company_id)
        .eq("tax_number", parsed.partner_tax_number)
        .is("deleted_at", null)
        .limit(1)
        .maybeSingle()
    : { data: null };

  const update: Record<string, unknown> = {
    processing_status: "needs_review",
    direction: parsed.direction,
    doc_kind: parsed.doc_kind,
    targy: parsed.targy,
    irat_szama: parsed.irat_szama,
    issue_date: parsed.issue_date,
    due_date: parsed.due_date,
    partner_id: partner?.id ?? null,
  };
  if (parsed.erkezett_at) update.erkezett_at = parsed.erkezett_at;
  if (parsed.melleklet_db !== null) update.melleklet_db = parsed.melleklet_db;
  if (parsed.gross_amount !== null || parsed.net_amount !== null || parsed.vat_amount !== null) {
    update.net_amount = parsed.net_amount;
    update.vat_amount = parsed.vat_amount;
    update.gross_amount = parsed.gross_amount;
    update.currency = parsed.currency ?? "HUF";
  }

  const { error: updErr } = await admin
    .from("document")
    .update(update)
    .eq("id", documentId)
    .eq("processing_status", "extracting");

  if (updErr) throw new Error(`failed to update document: ${updErr.message}`);
}
