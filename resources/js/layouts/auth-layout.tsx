import { FlashListener } from '@/components/common/flash-listener';
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';

export default function AuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <AuthLayoutTemplate title={title} description={description}>
            <FlashListener />
            {children}
        </AuthLayoutTemplate>
    );
}
