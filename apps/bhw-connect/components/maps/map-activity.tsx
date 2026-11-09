"use client";
import {
  createContext,
  useContext,
  useEffect,
  useId,
  useState,
  useSyncExternalStore,
  type ReactNode,
} from "react";
import { createMapActivity } from "@/lib/maps/activity";
const Context = createContext<ReturnType<typeof createMapActivity> | null>(null);
export function MapActivityProvider({ children }: { children: ReactNode }) {
  const [activity] = useState(() => createMapActivity(2));
  return <Context.Provider value={activity}>{children}</Context.Provider>;
}
const noopSubscribe = () => () => {};
const serverSnapshot = () => false;
/** A lease gates both the map and its data request. Offscreen cards release their lease. */
export function useMapActivity(eligible: boolean) {
  const activity = useContext(Context);
  const id = useId();
  useEffect(() => {
    if (eligible && activity) return activity.request(id);
  }, [eligible, activity, id]);
  const granted = useSyncExternalStore(
    activity?.subscribe ?? noopSubscribe,
    () => activity?.has(id) ?? eligible,
    serverSnapshot,
  );
  return eligible && granted;
}
