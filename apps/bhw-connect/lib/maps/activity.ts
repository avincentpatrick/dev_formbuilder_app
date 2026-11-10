/** Per-gallery FIFO leases. No global server/request state; one owner per mounted provider. */
export function createMapActivity(limit = 2) {
  if (!Number.isInteger(limit) || limit < 1) throw new Error("Invalid map limit");
  const queue: string[] = [];
  const listeners = new Set<() => void>();
  const notify = () => listeners.forEach((listener) => listener());
  return {
    has(id: string) {
      const index = queue.indexOf(id);
      return index >= 0 && index < limit;
    },
    request(id: string) {
      if (!queue.includes(id)) {
        queue.push(id);
        notify();
      }
      return () => {
        const index = queue.indexOf(id);
        if (index >= 0) {
          queue.splice(index, 1);
          notify();
        }
      };
    },
    subscribe(listener: () => void) {
      listeners.add(listener);
      return () => {
        listeners.delete(listener);
      };
    },
  };
}
