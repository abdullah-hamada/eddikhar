import { useState } from 'react';
import { Menu, X } from 'lucide-react';
import Sidebar from './Sidebar';
import { cn } from '../../lib/cn';

export default function AppShell({ children }) {
    const [mobileOpen, setMobileOpen] = useState(false);

    return (
        <div className="flex min-h-screen bg-[var(--color-bg)]">
            <aside className="sticky top-0 hidden h-screen w-60 shrink-0 border-r border-[var(--color-border)] bg-[var(--color-surface)] lg:block">
                <Sidebar />
            </aside>

            <div
                className={cn(
                    'fixed inset-0 z-40 lg:hidden',
                    mobileOpen ? 'pointer-events-auto' : 'pointer-events-none',
                )}
            >
                <div
                    className={cn(
                        'absolute inset-0 bg-black/30 transition-opacity',
                        mobileOpen ? 'opacity-100' : 'opacity-0',
                    )}
                    onClick={() => setMobileOpen(false)}
                />
                <aside
                    className={cn(
                        'absolute inset-y-0 left-0 w-64 border-r border-[var(--color-border)] bg-[var(--color-surface)] transition-transform',
                        mobileOpen ? 'translate-x-0' : '-translate-x-full',
                    )}
                >
                    <Sidebar onNavigate={() => setMobileOpen(false)} />
                </aside>
            </div>

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-30 flex h-14 items-center justify-between border-b border-[var(--color-border)] bg-[var(--color-surface)] px-4 lg:hidden">
                    <button
                        type="button"
                        onClick={() => setMobileOpen(true)}
                        className="rounded-md p-2 text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]"
                        aria-label="Open navigation"
                    >
                        <Menu className="size-5" />
                    </button>
                    <div className="flex items-center gap-2">
                        <Logo />
                        <span className="text-sm font-semibold">Eddikhar</span>
                    </div>
                    <div className="size-8" />
                </header>

                <main className="flex-1">
                    <div className="mx-auto w-full max-w-[1280px] px-5 py-6 lg:px-8 lg:py-8">
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}

function Logo() {
    return (
        <div className="flex size-7 items-center justify-center rounded-md bg-[var(--color-accent)] text-white">
            <span className="text-xs font-bold">E</span>
        </div>
    );
}
