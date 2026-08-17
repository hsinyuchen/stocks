import AppShell from '../Layouts/AppShell';
import { useI18n } from '../i18n';

export default function Placeholder({ title, status }) {
    const { t } = useI18n();

    return (
        <AppShell title={title}>
            <section className="placeholder-panel">
                <p className="section-kicker">{t('placeholder.comingSoon')}</p>
                <h2>{title}</h2>
                <p>{status}</p>
            </section>
        </AppShell>
    );
}