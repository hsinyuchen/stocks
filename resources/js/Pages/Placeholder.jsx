import AppShell from '../Layouts/AppShell';

export default function Placeholder({ title, status }) {
    return (
        <AppShell title={title}>
            <section className="placeholder-panel">
                <p className="section-kicker">Coming next</p>
                <h2>{title}</h2>
                <p>{status}</p>
            </section>
        </AppShell>
    );
}
