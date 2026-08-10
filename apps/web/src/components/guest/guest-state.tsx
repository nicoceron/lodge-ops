"use client";

import { createContext, useContext, useMemo, useSyncExternalStore, type ReactNode } from "react";
import {
  initialGuestPortalState,
  type GuestPortalState,
} from "@/data/guest-demo";

type GuestPortalContextValue = {
  state: GuestPortalState;
  updateState: (updater: (current: GuestPortalState) => GuestPortalState) => void;
  completion: Record<string, boolean>;
  completedCount: number;
  completionPercent: number;
};

const GuestPortalContext = createContext<GuestPortalContextValue | null>(null);
const initialStateJson = JSON.stringify(initialGuestPortalState);
const memoryState = new Map<string, string>();

export function GuestPortalProvider({ token, children }: { token: string; children: ReactNode }) {
  const storageKey = `lodgeops:guest:${token}`;
  const stateEvent = `${storageKey}:change`;

  const serializedState = useSyncExternalStore(
    (onStoreChange) => {
      window.addEventListener(stateEvent, onStoreChange);
      return () => window.removeEventListener(stateEvent, onStoreChange);
    },
    () => {
      const inMemory = memoryState.get(storageKey);
      if (inMemory) return inMemory;
      try {
        return window.sessionStorage.getItem(storageKey) ?? initialStateJson;
      } catch {
        return initialStateJson;
      }
    },
    () => initialStateJson,
  );

  const state = useMemo(() => JSON.parse(serializedState) as GuestPortalState, [serializedState]);

  const updateState = (updater: (current: GuestPortalState) => GuestPortalState) => {
    const next = updater(state);
    const nextJson = JSON.stringify(next);
    memoryState.set(storageKey, nextJson);
    try {
      window.sessionStorage.setItem(storageKey, nextJson);
    } catch {
      // A private browsing policy may block session storage; in-memory progress still works.
    }
    window.dispatchEvent(new Event(stateEvent));
  };

  const completion = useMemo(
    () => ({
      trip: true,
      "pre-arrival": state.preArrivalComplete,
      documents: state.waiver.accepted,
      payments: state.paymentEvidence.status === "accepted" || state.paymentEvidence.status === "not-needed",
      folio: false,
      survey: state.survey.submitted,
    }),
    [state],
  );

  const completedCount = Object.values(completion).filter(Boolean).length;
  const completionPercent = Math.round((completedCount / Object.keys(completion).length) * 100);

  return (
    <GuestPortalContext.Provider
      value={{ state, updateState, completion, completedCount, completionPercent }}
    >
      {children}
    </GuestPortalContext.Provider>
  );
}

export function useGuestPortal() {
  const value = useContext(GuestPortalContext);
  if (!value) throw new Error("useGuestPortal must be used within GuestPortalProvider");
  return value;
}
