import { createSupabaseAdminClient } from "@/lib/supabase/admin";
import { extractionResultSchema, extractionToolSchema, type ExtractionResult } from "./schema";
import { combineConfidence, runValidators } from "./confidence";
import { PROMPT_VERSION, SYSTEM_PROMPT, USER_PROMPT } from "./prompt";

// Extraction runs through OpenRouter (OpenAI-compatible API).
// Set EXTRACTION_MODEL to any OpenRouter slug that supports PDF/image input
// and tool calling, e.g. "anthropic/claude-sonnet-5".
const OPENROUTER_BASE_URL = process.env.OPENROUTER_BASE_URL ?? "https://openrouter.ai/api/v1";
const MODEL = process.env.EXTRACTION_MODEL ?? "anthropic/claude-sonnet-4.5";

type OpenRouterResponse = {
  model?: string;
  choices?: {
    message?: {
      tool_calls?: { function?: { name?: string; arguments?: string } }[];
    };
  }[];
  usage?: { cost?: number };
  error?: { message?: string };
};

async function callOpenRouter(
  mimeType: string,
  filename: string,
  base64: string
): Promise<OpenRouterResponse> {
  const apiKey = process.env.OPENROUTER_API_KEY;
  if (!apiKey) throw new Error("OPENROUTER_API_KEY is not set");

  const filePart =
    mimeType === "application/pdf"
      ? {
          type: "file",
          file: { filename, file_data: `data:application/pdf;base64,${base64}` },
        }
      : {
          type: "image_url",
          image_url: { url: `data:${mimeType};base64,${base64}` },
        };

  const res = await fetch(`${OPENROUTER_BASE_URL}/chat/completions`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${apiKey}`,
      "Content-Type": "application/json",
      "X-Title": "Szamlafolyo",
    },
    body: JSON.stringify({
      model: MODEL,
      max_tokens: 2048,
      messages: [
        { role: "system", content: SYSTEM_PROMPT },
        { role: "user", content: [filePart, { type: "text", text: USER_PROMPT }] },
      ],
      tools: [
        {
          type: "function",
          function: {
            name: "record_extraction",
            description: "Rogziti az iratbol kinyert iktatasi mezoket.",
            parameters: extractionToolSchema,
          },
        },
      ],
      tool_choice: { type: "function", function: { name: "record_extraction" } },
      usage: { include: true },
    }),
  });

  const body = (await res.json().catch(() => null)) as OpenRouterResponse | null;
  if (!res.ok || !body) {
    const detail = body?.error?.message ?? `HTTP ${res.status}`;
    throw new Error(`OpenRouter request failed: ${detail}`);
  }
  if (body.error) {
    throw new Error(`OpenRouter error: ${body.error.message}`);
  }
  return body;
}

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
    .select("storage_path, mime_type, original_filename")
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

  let response: OpenRouterResponse | null = null;
  let parsed: ExtractionResult | null = null;
  let parseError: string | null = null;

  try {
    response = await callOpenRouter(
      file.mime_type ?? "application/pdf",
      file.original_filename ?? "irat.pdf",
      base64
    );

    const toolCall = response.choices?.[0]?.message?.tool_calls?.find(
      (c) => c.function?.name === "record_extraction"
    );
    if (!toolCall?.function?.arguments) {
      parseError = "no record_extraction tool call in response";
    } else {
      let args: unknown;
      try {
        args = JSON.parse(toolCall.function.arguments);
      } catch {
        parseError = "tool call arguments are not valid JSON";
      }
      if (args !== undefined) {
        const result = extractionResultSchema.safeParse(args);
        if (result.success) {
          parsed = result.data;
        } else {
          parseError = "schema validation failed: " + result.error.message;
        }
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
    cost: response?.usage?.cost ?? null,
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
