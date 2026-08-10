import "server-only";

import { cookies } from "next/headers";
import {
  guestDocument,
  guestReservation,
  initialGuestPortalState,
  type GuestDocumentData,
  type GuestPortalState,
  type GuestReservationData,
} from "@/data/guest-demo";

export const guestPortalCookie = "lodgeops_guest_portal";

type ApiReservation = {
  portal: { session_expires_at: string };
  reservation: {
    confirmation_number: string;
    starts_at: string;
    ends_at: string;
    adults: number;
    children: number;
    currency: string;
    property: { name: string; timezone: string; address: string | null };
    guest: { preferred_name: string; email: string; mobile: string };
    room: string | null;
  };
  itinerary: Array<{
    day: string;
    title: string;
    starts_at: string;
    ends_at: string;
    meeting_point: string | null;
    detail: string | null;
    type: string;
  }>;
  readiness: { pre_arrival: boolean; waiver: boolean; payment: boolean; folio_final: boolean; survey: boolean };
  pre_arrival: {
    complete: boolean;
    profile: Record<string, string> | null;
    travel: Record<string, string> | null;
    preferences: Record<string, string | boolean> | null;
  };
  document: null | {
    slug: string;
    title: string;
    version: string;
    body: string;
    body_hash: string;
    acknowledged: boolean;
    signature: string | null;
    acknowledged_at: string | null;
  };
  payment: {
    currency: string;
    balance_minor: number;
    evidence: null | { file_name: string; status: string; submitted_at: string };
  };
  survey: { available: boolean; submitted: boolean; responded_at: string | null };
};

type ApiFolio = {
  currency: string;
  is_final: boolean;
  lines: Array<{ description: string; amount_minor: number; posted_at: string }>;
};

export type GuestPortalPageData = {
  mode: "live" | "demo";
  state: GuestPortalState;
  reservation: GuestReservationData;
  document: GuestDocumentData | null;
};

export function guestPortalDemoEnabled() {
  return process.env.GUEST_PORTAL_DEMO_MODE === "true";
}

function apiUrl(path: string) {
  const base = process.env.API_INTERNAL_URL ?? process.env.LARAVEL_API_URL;
  if (!base) throw new Error("LARAVEL_API_URL or API_INTERNAL_URL is required when guest portal demo mode is disabled.");
  return `${base.replace(/\/$/, "")}/api/v1/guest-portal/${path}`;
}

export async function exchangeGuestPortalToken(token: string) {
  const response = await fetch(apiUrl("exchange"), {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ token }),
    cache: "no-store",
  });

  if (!response.ok) return null;
  const payload = await response.json() as { data: { access_token: string; expires_at: string } };
  return payload.data;
}

async function portalFetch(path: string, init?: RequestInit) {
  const cookieStore = await cookies();
  const token = cookieStore.get(guestPortalCookie)?.value;
  if (!token) return null;

  return fetch(apiUrl(path), {
    ...init,
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${token}`,
      ...(init?.body && !(init.body instanceof FormData) ? { "Content-Type": "application/json" } : {}),
      ...init?.headers,
    },
    cache: "no-store",
  });
}

export async function getGuestPortalPageData(): Promise<GuestPortalPageData | null> {
  if (guestPortalDemoEnabled()) {
    return { mode: "demo", state: initialGuestPortalState, reservation: guestReservation, document: guestDocument };
  }

  const [reservationResponse, folioResponse] = await Promise.all([
    portalFetch("reservation"),
    portalFetch("folio"),
  ]);

  if (!reservationResponse || !folioResponse || reservationResponse.status === 401 || folioResponse.status === 401) return null;
  if (!reservationResponse.ok || !folioResponse.ok) throw new Error("The guest portal service is temporarily unavailable.");

  const reservationPayload = await reservationResponse.json() as { data: ApiReservation };
  const folioPayload = await folioResponse.json() as { data: ApiFolio };
  return mapApiData(reservationPayload.data, folioPayload.data);
}

export async function forwardGuestPortalRequest(path: string, method: "POST" | "PUT", body: unknown, multipart = false) {
  if (guestPortalDemoEnabled()) {
    return Response.json({ data: { demo: true } });
  }

  const response = await portalFetch(path, {
    method,
    body: multipart ? body as FormData : JSON.stringify(body),
  });
  if (!response) return Response.json({ message: "Guest portal session unavailable." }, { status: 401 });
  const payload = await response.text();

  return new Response(payload, {
    status: response.status,
    headers: { "Content-Type": response.headers.get("Content-Type") ?? "application/json" },
  });
}

function mapApiData(api: ApiReservation, folio: ApiFolio): GuestPortalPageData {
  const startsAt = new Date(api.reservation.starts_at);
  const endsAt = new Date(api.reservation.ends_at);
  const dateFormat = new Intl.DateTimeFormat("en", { day: "numeric", month: "long", year: "numeric" });
  const dateTimeFormat = new Intl.DateTimeFormat("en", { weekday: "long", day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" });
  const state: GuestPortalState = {
    profile: {
      preferredName: api.pre_arrival.profile?.preferred_name ?? api.reservation.guest.preferred_name ?? "Guest",
      email: api.pre_arrival.profile?.email ?? api.reservation.guest.email ?? "",
      mobile: api.pre_arrival.profile?.mobile ?? api.reservation.guest.mobile ?? "",
      emergencyName: api.pre_arrival.profile?.emergency_name ?? "",
      emergencyPhone: api.pre_arrival.profile?.emergency_phone ?? "",
    },
    travel: {
      arrivalMethod: (api.pre_arrival.travel?.arrival_method as "flight" | "car" | "other") ?? "flight",
      arrivalReference: api.pre_arrival.travel?.arrival_reference ?? "",
      arrivalTime: toLocalInput(api.pre_arrival.travel?.arrival_time),
      departureReference: api.pre_arrival.travel?.departure_reference ?? "",
      departureTime: toLocalInput(api.pre_arrival.travel?.departure_time),
    },
    preferences: {
      dietaryStyle: String(api.pre_arrival.preferences?.dietary_style ?? "No preference"),
      allergies: String(api.pre_arrival.preferences?.allergies ?? ""),
      accessibility: String(api.pre_arrival.preferences?.accessibility ?? ""),
      medicalConsent: Boolean(api.pre_arrival.preferences?.medical_consent),
    },
    preArrivalComplete: api.readiness.pre_arrival,
    waiver: {
      accepted: api.readiness.waiver,
      signature: api.document?.signature ?? "",
      signedAt: api.document?.acknowledged_at ?? null,
    },
    paymentEvidence: {
      fileName: api.payment.evidence?.file_name ?? null,
      status: api.readiness.payment
        ? "accepted"
        : api.payment.evidence?.status === "review_pending" ? "review-pending" : "ready",
    },
    folioFinal: api.readiness.folio_final,
    survey: { submitted: api.survey.submitted, stayRating: 0, guideRating: 0, comment: "" },
  };
  const itinerary = api.itinerary.map((item) => ({
    day: item.day,
    title: item.title,
    time: new Intl.DateTimeFormat("en", { hour: "2-digit", minute: "2-digit" }).format(new Date(item.starts_at)),
    detail: item.detail ?? item.meeting_point ?? "Your host will share the final details.",
    type: item.type,
  }));
  const reservation: GuestReservationData = {
    property: api.reservation.property.name,
    location: api.reservation.property.address ?? "",
    guestName: api.reservation.guest.preferred_name || "Guest",
    partyName: `${api.reservation.guest.preferred_name || "Guest"} party`,
    reservationCode: api.reservation.confirmation_number,
    stay: `${dateFormat.format(startsAt)} – ${dateFormat.format(endsAt)}`,
    nights: Math.max(1, Math.round((endsAt.getTime() - startsAt.getTime()) / 86_400_000)),
    guests: api.reservation.adults + api.reservation.children,
    room: api.reservation.room ?? "To be assigned",
    program: itinerary[0]?.title ?? "Tailored lodge stay",
    host: "Lodge team",
    hostPhone: "",
    arrival: dateTimeFormat.format(startsAt),
    departure: dateTimeFormat.format(endsAt),
    balanceMinor: api.payment.balance_minor,
    currency: api.payment.currency,
    itinerary,
    folio: folio.lines.map((line) => ({
      date: new Intl.DateTimeFormat("en", { day: "2-digit", month: "short" }).format(new Date(line.posted_at)),
      description: line.description,
      amountMinor: line.amount_minor,
    })),
    paymentInstructions: [],
  };

  return {
    mode: "live",
    state,
    reservation,
    document: api.document ? {
      slug: api.document.slug,
      title: api.document.title,
      version: api.document.version,
      body: api.document.body,
      bodyHash: api.document.body_hash,
    } : null,
  };
}

function toLocalInput(value?: string) {
  if (!value) return "";
  const date = new Date(value);
  const offset = date.getTimezoneOffset() * 60_000;
  return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}
