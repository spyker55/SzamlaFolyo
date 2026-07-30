// Fire-and-forget kick of the extraction worker. Durability comes from the
// cron sweep, not from this call — a lost kick only means up to a minute of
// extra latency.

export function workerBaseUrl(): string {
  if (process.env.WORKER_BASE_URL) return process.env.WORKER_BASE_URL;
  if (process.env.VERCEL_URL) return `https://${process.env.VERCEL_URL}`;
  return "http://localhost:3000";
}

export async function kickExtraction(documentId: string): Promise<void> {
  try {
    await fetch(`${workerBaseUrl()}/api/jobs/extract`, {
      method: "POST",
      headers: {
        "content-type": "application/json",
        "x-worker-secret": process.env.WORKER_SECRET ?? "",
      },
      body: JSON.stringify({ documentId }),
    });
  } catch (err) {
    console.error("extraction kick failed (sweep will retry)", err);
  }
}
