import type { Metadata } from "next";

export const metadata: Metadata = { title: "Features", description: "Reservations, resources, guest journeys, operations, finance, and reporting for independent lodges." };

const sections = [
  ["Reservations and sales", "From inquiry to checkout", ["Unified reservation composer", "Guest and companion profiles", "Proposal versions and conversion", "Deposits, payments, and folios", "Reservation status history"]],
  ["Resources and calendar", "Every constraint in one schedule", ["Rooms, guides, vehicles, horses, boats, and equipment", "Capacity, overlap, block, and buyout checks", "Activities and service occurrences", "Guide skills and assignment suggestions", "Property-local timezone display"]],
  ["Daily operations", "The right work for every role", ["Arrival and departure board", "Kitchen dietary preparation", "Housekeeping turnovers and stayovers", "Guide assignments", "Generated and assigned task queues"]],
  ["Guest experience", "Preparation without another account", ["One-time secure magic links", "Itinerary and lodge details", "Pre-arrival contact and travel forms", "Versioned document acknowledgement", "Payment evidence, folio, and feedback"]],
  ["Finance and reporting", "Numbers tied to the stay", ["Booked revenue and cash collected", "Receivables and deposit status", "Program costs and channel commissions", "Native-currency margin", "Auditable exports and reconciliation"]],
  ["Tenant administration", "One platform, separate operations", ["Membership and property scopes", "Owner, manager, sales, operations, guide, kitchen, housekeeping, finance, and viewer roles", "Tenant-scoped integrations", "Private document workflows", "Queue and audit context restoration"]],
];

export default function FeaturesPage() {
  return <>
    <section className="page-hero"><div className="shell"><div className="eyebrow">Platform</div><h1>Every workflow around the stay, connected.</h1><p className="lede">Inn replaces fragmented spreadsheets and booking variants with a coherent operating model from first inquiry through post-stay closeout.</p></div></section>
    <section className="section"><div className="shell detail-grid">{sections.map(([title, heading, items]) => <article className="detail-card" key={title as string}><div className="eyebrow">{title as string}</div><h3>{heading as string}</h3><ul>{(items as string[]).map(item => <li key={item}>{item}</li>)}</ul></article>)}</div></section>
  </>;
}
