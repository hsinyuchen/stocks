import { Menu, X } from 'lucide-react';
import { useI18n } from '../i18n';

export default function HamburgerButton({ isOpen, onClick }) {
    const { t } = useI18n();
    const Icon = isOpen ? X : Menu;

    return (
        <button
            type="button"
            className="icon-button"
            aria-label={isOpen ? t('appShell.closeNav') : t('appShell.openNav')}
            aria-expanded={isOpen}
            onClick={onClick}
            title={isOpen ? t('appShell.closeMenu') : t('appShell.openMenu')}
        >
            <Icon aria-hidden="true" size={22} strokeWidth={2.2} />
        </button>
    );
}