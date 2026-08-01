import { beforeEach, describe, expect, it, vi } from "vitest";

// The module holds state on purpose, so every test gets a fresh copy of it.
async function fresh() {
  vi.resetModules();
  return import("@/lib/ui/upload-intent");
}

describe("upload intent", () => {
  beforeEach(() => {
    vi.resetModules();
  });

  it("opens the dialog straight away when the Beérkező is already on screen", async () => {
    // The case that started this: the sidebar button navigated to a page the
    // user was already on, so nothing happened at all.
    const { requestUpload, onUploadRequest } = await fresh();
    const open = vi.fn();
    onUploadRequest(open);

    requestUpload();

    expect(open).toHaveBeenCalledTimes(1);
  });

  it("holds the request for the screen it is navigating to", async () => {
    const { requestUpload, onUploadRequest } = await fresh();

    // Clicked from another page: nothing is listening yet.
    requestUpload();

    const open = vi.fn();
    onUploadRequest(open);
    expect(open).toHaveBeenCalledTimes(1);
  });

  it("delivers a held request only once", async () => {
    const { requestUpload, onUploadRequest } = await fresh();
    requestUpload();

    const first = vi.fn();
    const off = onUploadRequest(first);
    off();

    // Leaving and coming back to the Beérkező must not re-open the dialog.
    const second = vi.fn();
    onUploadRequest(second);

    expect(first).toHaveBeenCalledTimes(1);
    expect(second).not.toHaveBeenCalled();
  });

  it("stops calling a listener that has unsubscribed", async () => {
    const { requestUpload, onUploadRequest } = await fresh();
    const open = vi.fn();
    onUploadRequest(open)();

    requestUpload();

    expect(open).not.toHaveBeenCalled();
  });

  it("does not open the dialog on its own", async () => {
    const { onUploadRequest } = await fresh();
    const open = vi.fn();
    onUploadRequest(open);
    expect(open).not.toHaveBeenCalled();
  });
});
