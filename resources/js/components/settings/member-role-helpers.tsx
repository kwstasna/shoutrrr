import { Crown, Shield, User } from 'lucide-react';

export function roleIcon(role: string) {
    switch (role) {
        case 'owner':
            return <Crown className="size-4 text-yellow-600" />;
        case 'admin':
            return <Shield className="size-4 text-blue-600" />;
        default:
            return <User className="size-4 text-muted-foreground" />;
    }
}

export function roleBadgeVariant(
    role: string,
): 'default' | 'secondary' | 'outline' {
    switch (role) {
        case 'owner':
            return 'default';
        case 'admin':
            return 'secondary';
        default:
            return 'outline';
    }
}
