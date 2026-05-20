import { Link } from 'react-router-dom';
import { Compass } from 'lucide-react';
import { Button } from '../components/ui/Button';

export default function NotFoundPage() {
    return (
        <div className="flex flex-col items-center justify-center px-6 py-24 text-center">
            <div className="flex size-12 items-center justify-center rounded-full bg-[var(--color-surface-hover)] text-[var(--color-text-tertiary)]">
                <Compass className="size-6" />
            </div>
            <h1 className="mt-4 text-xl font-semibold text-[var(--color-text-primary)]">Page not found</h1>
            <p className="mt-1 text-[13px] text-[var(--color-text-tertiary)]">
                The page you’re looking for doesn’t exist or has moved.
            </p>
            <Link to="/" className="mt-5">
                <Button variant="secondary" size="sm">
                    Back to dashboard
                </Button>
            </Link>
        </div>
    );
}
