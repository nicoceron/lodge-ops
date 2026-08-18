import Link from "next/link";

const manageUrl = process.env.NEXT_PUBLIC_MANAGE_URL ?? "http://localhost:8000/manage";

const features = [
  ["01", "One reservation book", "Replace disconnected room, group, guide, and activity bookings with one reservation and allocation workflow."],
  ["02", "Live operations", "Arrivals, departures, housekeeping, kitchen restrictions, guide assignments, and open tasks stay in the same operational picture."],
  ["03", "Money without mystery", "Deposits, manual payments, folios, costs, commissions, receivables, and margin use explicit minor-unit accounting."],
  ["04", "Every resource, scheduled", "Rooms, guides, horses, vehicles, boats, equipment, blocks, buyouts, and capacity rules share one calendar."],
  ["05", "A guest-ready journey", "Secure magic links handle itineraries, pre-arrival details, waivers, payment evidence, folios, and post-stay feedback."],
  ["06", "Built for many lodges", "Tenant memberships, property scopes, role permissions, and database-enforced ownership keep each operation isolated."],
];

export default function Home() {
  return (
    <>
      <section className="hero">
        <div className="shell hero-copy">
          <div className="eyebrow">Lodge operations, finally connected</div>
          <h1>Run the whole lodge from <span>one calm place.</span></h1>
          <p className="lede">Inn brings reservations, guest preparation, resources, teams, payments, and reporting together—without forcing independent operators into hotel software that does not fit.</p>
          <div className="hero-actions">
            <a className="button" href={manageUrl}>Open Inn <span aria-hidden="true">→</span></a>
            <Link className="button button-secondary" href="/features">Explore the platform</Link>
          </div>
        </div>
      </section>

      <section className="proof-strip" aria-label="Platform highlights">
        <div className="shell proof-grid">
          <div className="proof-item"><strong>One calendar</strong><span>Rooms, guides, activities, and gear</span></div>
          <div className="proof-item"><strong>One guest record</strong><span>Sales history through post-stay care</span></div>
          <div className="proof-item"><strong>One operations board</strong><span>Every role sees the work that matters</span></div>
          <div className="proof-item"><strong>One source of truth</strong><span>Tenant-safe Laravel domain workflows</span></div>
        </div>
      </section>

      <section className="section">
        <div className="shell">
          <div className="section-head">
            <div><div className="eyebrow">The complete operating picture</div><h2>Less tab switching.<br/>More hosting.</h2></div>
            <p>Purpose-built workflows connect the commercial promise to the people, resources, preparation, and money required to deliver it.</p>
          </div>
          <div className="feature-grid">
            {features.map(([number, title, description]) => <article className="feature-card" key={number}><div className="number">{number}</div><h3>{title}</h3><p>{description}</p></article>)}
          </div>
        </div>
      </section>

      <section className="section section-dark">
        <div className="shell workspace">
          <div className="workspace-copy">
            <div className="eyebrow">The staff workspace</div>
            <h2>Built around the day the lodge is actually having.</h2>
            <p>The authenticated Inn application runs in Laravel and Filament, so login, roles, tenant switching, workflows, and records share one secure application boundary.</p>
            <ul className="check-list">
              <li>Role-specific calendar and work queues</li>
              <li>Reservation composer with resource conflict checks</li>
              <li>Kitchen, housekeeping, guide, finance, and owner views</li>
              <li>No duplicate frontend authentication or browser API proxy</li>
            </ul>
          </div>
          <div className="product-window" aria-label="Illustration of the Inn operations board">
            <div className="window-bar"><i></i><i></i><i></i></div>
            <div className="window-body">
              <div className="window-nav"><strong>Inn</strong><span>Operations</span><span>Calendar</span><span>Reservations</span><span>Guests</span><span>Finance</span></div>
              <div className="window-main">
                <h3>Today at North Ridge Lodge</h3>
                <div className="metric-grid"><div className="metric"><b>8</b><small>Arrivals</small></div><div className="metric"><b>5</b><small>Departures</small></div><div className="metric"><b>3</b><small>Open tasks</small></div></div>
                <div className="schedule-row"><span>08:30</span><b>Laguna trail group</b><span className="tag">Ready</span></div>
                <div className="schedule-row"><span>11:00</span><b>Rooms 4–8 turnover</b><span className="tag">In progress</span></div>
                <div className="schedule-row"><span>15:20</span><b>Airport arrival · 6 guests</b><span className="tag">Assigned</span></div>
                <div className="schedule-row"><span>18:30</span><b>Dietary prep review</b><span className="tag">2 alerts</span></div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="section section-muted">
        <div className="shell">
          <div className="section-head"><div><div className="eyebrow">Designed for independent hospitality</div><h2>Your operation is not a generic hotel.</h2></div><p>Inn respects programs, guides, shared resources, remote logistics, group travel, and the high-touch guest care that defines a great lodge.</p></div>
          <div className="story-grid">
            <article className="story"><blockquote>“Every team can work from the same reservation without seeing information their role does not need.”</blockquote><cite>Role-scoped by design</cite></article>
            <article className="story"><blockquote>“The calendar understands a room, a guide, and a boat as resources in the same trip.”</blockquote><cite>One allocation model</cite></article>
            <article className="story"><blockquote>“Guest preparation flows directly into the kitchen and operations day.”</blockquote><cite>Connected workflows</cite></article>
          </div>
        </div>
      </section>

      <section className="section"><div className="shell cta"><div><h2>Make the next arrival day calmer.</h2><p>Sign in to your secure Inn workspace and run the operation from one place.</p></div><a className="button" href={manageUrl}>Open the staff application →</a></div></section>
    </>
  );
}
