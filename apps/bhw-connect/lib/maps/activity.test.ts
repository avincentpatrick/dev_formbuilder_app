import { expect, it, vi } from "vitest";
import { createMapActivity } from "./activity";
it("limits leases, queues visible maps and grants the next when an owner leaves", () => {
  const activity = createMapActivity(2);
  const listener = vi.fn();
  const unsubscribe = activity.subscribe(listener);
  const leaveA = activity.request("a");
  const leaveB = activity.request("b");
  const leaveC = activity.request("c");
  expect(["a", "b", "c"].filter(activity.has)).toEqual(["a", "b"]);
  leaveA();
  expect(["a", "b", "c"].filter(activity.has)).toEqual(["b", "c"]);
  leaveA(); // repeated cleanup cannot evict another owner
  expect(activity.has("c")).toBe(true);
  leaveB();
  leaveC();
  expect(["a", "b", "c"].some(activity.has)).toBe(false);
  unsubscribe();
  listener.mockClear();
  activity.request("d");
  expect(listener).not.toHaveBeenCalled();
});
it("keeps galleries and requests isolated", () => {
  const a = createMapActivity(1);
  const b = createMapActivity(1);
  a.request("first");
  b.request("second");
  expect(a.has("second")).toBe(false);
  expect(b.has("second")).toBe(true);
  expect(() => createMapActivity(0)).toThrow();
});
