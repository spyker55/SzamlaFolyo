import { NextResponse, after } from "next/server";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { createSupabaseAdminClient } from "@/lib/supabase/admin";
import { claimAndRunExtraction } from "@/lib/jobs/claim";
import { storeIncomingFile, type StoreResult } from "@/lib/upload/store";

// The response returns immediately; after() keeps the function alive while
// the uploaded documents are extracted in-process (no HTTP self-call — that
// would die on Vercel Deployment Protection).
export const maxDuration = 300;

export async function POST(request: Request) {
  const supabase = await createSupabaseServerClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  if (!user) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }

  const { data: member } = await supabase
    .from("company_member")
    .select("company_id")
    .limit(1)
    .maybeSingle();

  if (!member) {
    return NextResponse.json({ error: "no company" }, { status: 403 });
  }

  const companyId = member.company_id as string;
  const formData = await request.formData();
  const files = formData.getAll("files").filter((f): f is File => f instanceof File);

  if (files.length === 0) {
    return NextResponse.json({ error: "no files" }, { status: 400 });
  }

  const admin = createSupabaseAdminClient();
  const results: StoreResult[] = [];
  const toExtract: string[] = [];

  for (const file of files) {
    const result = await storeIncomingFile({
      admin,
      companyId,
      bytes: Buffer.from(await file.arrayBuffer()),
      filename: file.name,
      mimeType: file.type,
      createdBy: user.id,
    });

    results.push(result);
    if (result.status === "created" && result.documentId) toExtract.push(result.documentId);
  }

  // Extract after the response is sent; the cron sweep is the safety net.
  after(async () => {
    for (const id of toExtract) {
      await claimAndRunExtraction(id);
    }
  });

  return NextResponse.json({ results });
}
