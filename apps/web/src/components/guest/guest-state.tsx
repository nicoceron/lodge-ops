"use client";

import { createContext, useContext, useMemo, useSyncExternalStore, type ReactNode } from "react";
import {
  guestDocument,
  guestReservation,
  initialGuestPortalState,
  type GuestDocumentData,
  type GuestPortalState,
  type GuestReservationData,
} from "@/data/guest-demo";

type GuestPortalContextValue = {
  state: GuestPortalState;
  reservation: GuestReservationData;
  document: GuestDocumentData | null;
  updateState: (updater: (current: GuestPortalState) => GuestPortalState) => void;
  savePreArrival: (draft: GuestPortalState) => Promise<void>;
  acknowledgeDocument: (signature: string) => Promise<void>;
  submitPaymentEvidence: (evidence: File) => Promise<void>;
  submitSurvey: (survey: { stayRating: number; guideRating: number; comment: string; shareWithTeam: boolean }) => Promise<void>;
  completion: Record<string, boolean>;
  completedCount: number;
  completionPercent: number;
};

const GuestPortalContext = createContext<GuestPortalContextValue | null>(null);
const memoryState = new Map<string, string>();

export function GuestPortalProvider({
  token,
  children,
  initialState = initialGuestPortalState,
  reservation = guestReservation,
  document = guestDocument,
  mode = "demo",
}: {
  token: string;
  children: ReactNode;
  initialState?: GuestPortalState;
  reservation?: GuestReservationData;
  document?: GuestDocumentData | null;
  mode?: "live" | "demo";
}) {
  const storageKey = `lodgeops:guest:${token}`;
  const stateEvent = `${storageKey}:change`;
  const initialStateJson = useMemo(() => JSON.stringify(initialState), [initialState]);

  const serializedState = useSyncExternalStore(
    (onStoreChange) => {
      window.addEventListener(stateEvent, onStoreChange);
      return () => window.removeEventListener(stateEvent, onStoreChange);
    },
    () => {
      const inMemory = memoryState.get(storageKey);
      if (inMemory) return inMemory;
      if (mode === "live") return initialStateJson;
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
    if (mode === "demo") {
      try {
        window.sessionStorage.setItem(storageKey, nextJson);
      } catch {
        // A private browsing policy may block session storage; in-memory progress still works.
      }
    }
    window.dispatchEvent(new Event(stateEvent));
  };

  const persist = async (action: string, method: "POST" | "PUT", payload: unknown) => {
    if (mode === "demo") return;
    const response = await fetch(`/guest/api/${action}`, {
      method,
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    if (!response.ok) {
      const error = await response.json().catch(() => ({ message: "Unable to save right now." })) as { message?: string };
      throw new Error(error.message ?? "Unable to save right now.");
    }
  };

  const savePreArrival = async (draft: GuestPortalState) => {
    await persist("pre-arrival", "PUT", {
      profile: {
        preferred_name: draft.profile.preferredName,
        email: draft.profile.email,
        mobile: draft.profile.mobile,
        emergency_name: draft.profile.emergencyName,
        emergency_phone: draft.profile.emergencyPhone,
      },
      travel: {
        arrival_method: draft.travel.arrivalMethod,
        arrival_reference: draft.travel.arrivalReference || null,
        arrival_time: new Date(draft.travel.arrivalTime).toISOString(),
        departure_reference: draft.travel.departureReference,
        departure_time: new Date(draft.travel.departureTime).toISOString(),
      },
      preferences: {
        dietary_style: draft.preferences.dietaryStyle,
        allergies: draft.preferences.allergies || null,
        accessibility: draft.preferences.accessibility || null,
        medical_consent: draft.preferences.medicalConsent,
      },
    });
    updateState(() => ({ ...draft, preArrivalComplete: true }));
  };

  const acknowledgeDocument = async (signature: string) => {
    if (!document) throw new Error("This document is unavailable.");
    await persist("waiver", "POST", {
      document_slug: document.slug,
      document_version: document.version,
      document_hash: document.bodyHash,
      signature,
      accepted: true,
    });
    updateState((current) => ({ ...current, waiver: { accepted: true, signature, signedAt: new Date().toISOString() } }));
  };

  const submitPaymentEvidence = async (evidence: File) => {
    if (mode === "live") {
      const body = new FormData();
      body.append("evidence", evidence);
      const response = await fetch("/guest/api/payment-evidence", { method: "POST", body });
      if (!response.ok) {
        const error = await response.json().catch(() => ({ message: "Unable to attach evidence right now." })) as { message?: string };
        throw new Error(error.message ?? "Unable to attach evidence right now.");
      }
    }
    updateState((current) => ({
      ...current,
      paymentEvidence: { fileName: evidence.name, status: "review-pending" },
    }));
  };

  const submitSurvey = async (survey: { stayRating: number; guideRating: number; comment: string; shareWithTeam: boolean }) => {
    await persist("survey", "POST", {
      stay_rating: survey.stayRating,
      guide_rating: survey.guideRating,
      comment: survey.comment || null,
      share_with_team: survey.shareWithTeam,
    });
    updateState((current) => ({
      ...current,
      survey: { submitted: true, stayRating: survey.stayRating, guideRating: survey.guideRating, comment: survey.comment },
    }));
  };

  const completion = useMemo(
    () => ({
      trip: true,
      "pre-arrival": state.preArrivalComplete,
      documents: state.waiver.accepted,
      payments: state.paymentEvidence.status === "accepted" || state.paymentEvidence.status === "not-needed",
      folio: state.folioFinal,
      survey: state.survey.submitted,
    }),
    [state],
  );

  const completedCount = Object.values(completion).filter(Boolean).length;
  const completionPercent = Math.round((completedCount / Object.keys(completion).length) * 100);

  return (
    <GuestPortalContext.Provider
      value={{
        state,
        reservation,
        document,
        updateState,
        savePreArrival,
        acknowledgeDocument,
        submitPaymentEvidence,
        submitSurvey,
        completion,
        completedCount,
        completionPercent,
      }}
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
