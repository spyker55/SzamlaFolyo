// "Irat feltöltése" in the sidebar and the file input in the Beérkező are in
// two different component trees, so the intent to upload travels through this
// module rather than through props.
//
// Deliberately not a query parameter: ?feltoltes=1 would survive a reload and
// pop the file dialog open on a page the user merely refreshed. Module state
// is lost on a full page load, which is exactly the right lifetime for it.

type Listener = () => void;

const listeners = new Set<Listener>();
let pending = false;

// Called from the sidebar. If the Beérkező is already on screen the dialog
// opens immediately, still inside the click that asked for it — which is what
// browsers require before they will open a file picker. If it is not, the
// request waits for the screen we are navigating to.
export function requestUpload(): void {
  if (listeners.size > 0) {
    for (const listener of listeners) listener();
    return;
  }
  pending = true;
}

export function onUploadRequest(listener: Listener): () => void {
  listeners.add(listener);
  if (pending) {
    pending = false;
    listener();
  }
  return () => {
    listeners.delete(listener);
  };
}
