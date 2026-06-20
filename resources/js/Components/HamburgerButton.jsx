import { Menu, X } from 'lucide-react';

export default function HamburgerButton({ isOpen, onClick }) {
    const Icon = isOpen ? X : Menu;

    return (
        <button
            type="button"
            className="icon-button"
            aria-label={isOpen ? 'Close navigation menu' : 'Open navigation menu'}
            aria-expanded={isOpen}
            onClick={onClick}
            title={isOpen ? 'Close menu' : 'Open menu'}
        >
            <Icon aria-hidden="true" size={22} strokeWidth={2.2} />
        </button>
    );
}
