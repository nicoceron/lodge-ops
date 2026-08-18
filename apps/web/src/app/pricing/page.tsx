import type { Metadata } from "next";

export const metadata: Metadata = { title: "Pricing", description: "Straightforward Inn plans for independent lodges and multi-property operators." };
const manageUrl = process.env.NEXT_PUBLIC_MANAGE_URL ?? "http://localhost:8000/manage";

const plans = [
  { name: "Single lodge", price: "$249", note: "For one property building a connected operation.", items: ["Unlimited staff roles", "Reservations and guest CRM", "Calendar and operations", "Manual payments and folios", "Secure guest portal"] },
  { name: "Growing operation", price: "$499", note: "For teams coordinating multiple programs and properties.", featured: true, items: ["Everything in Single lodge", "Multiple properties", "Advanced resource planning", "Automation and communications", "Finance and channel reporting"] },
  { name: "Portfolio", price: "Custom", note: "For operators needing implementation and provider activation.", items: ["Everything in Growing operation", "Migration assistance", "Custom retention controls", "Provider integration rollout", "Acceptance and load-test support"] },
];

export default function PricingPage() {
  return <>
    <section className="page-hero"><div className="shell"><div className="eyebrow">Pricing</div><h1>Software should simplify the operation—not the invoice.</h1><p className="lede">Choose a starting point, then activate provider integrations only when your team is ready to use them.</p></div></section>
    <section className="section"><div className="shell price-grid">{plans.map(plan => <article className={`price-card${plan.featured ? " featured" : ""}`} key={plan.name}>{plan.featured && <span className="eyebrow">Most popular</span>}<h3>{plan.name}</h3><div className="price">{plan.price}{plan.price.startsWith("$") && <small> / month</small>}</div><p>{plan.note}</p><ul>{plan.items.map(item => <li key={item}>{item}</li>)}</ul><a className="button" href={manageUrl}>Open Inn</a></article>)}</div></section>
  </>;
}
