import { Menu, X } from 'lucide-react';

export default function HamburgerButton({ isOpen, onClick }) {
    const Icon = isOpen ? X : Menu;

    return (
        <button
            type="button"
            className="icon-button"
            aria-label={isOpen ? '關閉導覽選單' : '開啟導覽選單'}
            aria-expanded={isOpen}
            onClick={onClick}
            title={isOpen ? '關閉選單' : '開啟選單'}
        >
            <Icon aria-hidden="true" size={22} strokeWidth={2.2} />
        </button>
    );
}