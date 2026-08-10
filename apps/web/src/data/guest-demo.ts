export const DEMO_GUEST_TOKEN = "g_7JvK2pQ9xR4mN8tW3cD6hF1sB5yE0uA";

export type GuestPortalState = {
  profile: {
    preferredName: string;
    email: string;
    mobile: string;
    emergencyName: string;
    emergencyPhone: string;
  };
  travel: {
    arrivalMethod: "flight" | "car" | "other";
    arrivalReference: string;
    arrivalTime: string;
    departureReference: string;
    departureTime: string;
  };
  preferences: {
    dietaryStyle: string;
    allergies: string;
    accessibility: string;
    medicalConsent: boolean;
  };
  preArrivalComplete: boolean;
  waiver: {
    accepted: boolean;
    signature: string;
    signedAt: string | null;
  };
  paymentEvidence: {
    fileName: string | null;
    status: "not-needed" | "ready" | "review-pending" | "accepted";
  };
  survey: {
    submitted: boolean;
    stayRating: number;
    guideRating: number;
    comment: string;
  };
};

export const initialGuestPortalState: GuestPortalState = {
  profile: {
    preferredName: "Alex",
    email: "alex@example.com",
    mobile: "+1 415 555 0186",
    emergencyName: "",
    emergencyPhone: "",
  },
  travel: {
    arrivalMethod: "flight",
    arrivalReference: "LA 896",
    arrivalTime: "2026-08-12T11:40",
    departureReference: "",
    departureTime: "",
  },
  preferences: {
    dietaryStyle: "No preference",
    allergies: "",
    accessibility: "",
    medicalConsent: false,
  },
  preArrivalComplete: false,
  waiver: {
    accepted: false,
    signature: "",
    signedAt: null,
  },
  paymentEvidence: {
    fileName: null,
    status: "ready",
  },
  survey: {
    submitted: false,
    stayRating: 0,
    guideRating: 0,
    comment: "",
  },
};

export const guestReservation = {
  property: "Estancia Viento Sur",
  location: "El Chaltén, Patagonia",
  guestName: "Alex Morgan",
  partyName: "Morgan party",
  reservationCode: "VS-260812-04",
  stay: "12–17 August 2026",
  nights: 5,
  guests: 2,
  room: "Andes Suite",
  program: "Patagonian Explorer",
  host: "Sofía",
  hostPhone: "+54 9 2966 555 014",
  arrival: "Wednesday, 12 August · 14:00",
  departure: "Monday, 17 August · 10:30",
  balanceMinor: 168000,
  currency: "USD",
  itinerary: [
    {
      day: "Wed 12",
      title: "Welcome to Viento Sur",
      time: "14:00",
      detail: "Private transfer, lodge orientation and fireside dinner.",
      type: "Arrival",
    },
    {
      day: "Thu 13",
      title: "Laguna Capri",
      time: "08:30",
      detail: "A gentle first hike with Mateo. Picnic lunch is provided.",
      type: "Guided hike",
    },
    {
      day: "Fri 14",
      title: "Estancia day",
      time: "09:30",
      detail: "Horsemanship, kitchen garden and an open afternoon.",
      type: "At the lodge",
    },
    {
      day: "Sat 15",
      title: "Fitz Roy sunrise",
      time: "05:45",
      detail: "Early departure. Breakfast and technical layers are packed.",
      type: "Guided hike",
    },
    {
      day: "Sun 16",
      title: "Choose your pace",
      time: "09:00",
      detail: "Select a glacier walk or a restorative day at the estancia.",
      type: "Flexible",
    },
    {
      day: "Mon 17",
      title: "Departure",
      time: "10:30",
      detail: "Private transfer to El Calafate airport.",
      type: "Departure",
    },
  ],
  folio: [
    { date: "02 Jun", description: "Patagonian Explorer · 5 nights", amountMinor: 630000 },
    { date: "02 Jun", description: "Private airport transfers", amountMinor: 42000 },
    { date: "03 Jun", description: "Booking deposit", amountMinor: -252000 },
    { date: "08 Aug", description: "Card payment", amountMinor: -252000 },
  ],
} as const;

export const guestNavigation = [
  { label: "Your trip", shortLabel: "Trip", segment: "", step: "trip" },
  { label: "Pre-arrival", shortLabel: "Prepare", segment: "pre-arrival", step: "pre-arrival" },
  { label: "Documents", shortLabel: "Docs", segment: "documents", step: "documents" },
  { label: "Payments", shortLabel: "Pay", segment: "payments", step: "payments" },
  { label: "Final folio", shortLabel: "Folio", segment: "folio", step: "folio" },
  { label: "Your feedback", shortLabel: "Feedback", segment: "survey", step: "survey" },
] as const;

export function formatMoney(minor: number) {
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: guestReservation.currency,
    maximumFractionDigits: 0,
  }).format(minor / 100);
}
