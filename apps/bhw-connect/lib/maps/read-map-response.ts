/** One bounded retry for transient gateway/service failures; never retry source availability. */
export async function readMapResponse(url: string, signal: AbortSignal): Promise<Response> {
  const response = await fetch(url, { signal });
  if (![502, 503, 504].includes(response.status) || response.headers.has("Retry-After"))
    return response;

  // Respect scope changes and unmounts during the delay, not just during the request.
  await new Promise<void>((resolve, reject) => {
    const cancel = () => {
      clearTimeout(timer);
      signal.removeEventListener("abort", cancel);
      reject(signal.reason);
    };
    const timer = setTimeout(() => {
      signal.removeEventListener("abort", cancel);
      resolve();
    }, 500);
    signal.addEventListener("abort", cancel, { once: true });
    if (signal.aborted) cancel();
  });
  signal.throwIfAborted();
  return fetch(url, { signal });
}
