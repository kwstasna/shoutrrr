import { Deferred, Form, Head, router, usePage } from '@inertiajs/react';
import {
    Crown,
    Mail,
    MoreVertical,
    Shield,
    Trash2,
    User,
    UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import WorkspaceController from '@/actions/App/Http/Controllers/WorkspaceController';
import Heading from '@/components/common/heading';
import InputError from '@/components/common/input-error';
import { MembersSkeleton } from '@/components/skeletons/members-skeleton';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { removeById, replaceById } from '@/lib/optimistic';

type Member = {
    id: string;
    user_id: string;
    name: string;
    email: string;
    avatar: string;
    role: string;
    is_owner: boolean;
    created_at: string;
};

type Invitation = {
    id: string;
    email: string;
    role: string;
    invited_by: string | null;
    expires_at: string;
    created_at: string;
};

type Props = {
    members?: Member[];
    pendingInvitations: Invitation[];
    canManage: boolean;
    availableRoles: string[];
};

function roleIcon(role: string) {
    switch (role) {
        case 'owner':
            return <Crown className="size-4 text-yellow-600" />;
        case 'admin':
            return <Shield className="size-4 text-blue-600" />;
        default:
            return <User className="size-4 text-muted-foreground" />;
    }
}

function roleBadgeVariant(role: string): 'default' | 'secondary' | 'outline' {
    switch (role) {
        case 'owner':
            return 'default';
        case 'admin':
            return 'secondary';
        default:
            return 'outline';
    }
}

function InviteMemberDialog({ availableRoles }: { availableRoles: string[] }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <UserPlus className="size-4" />
                    Invite member
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Invite a new member</DialogTitle>
                    <DialogDescription>
                        Send an invitation to join this workspace.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...WorkspaceSettingsController.inviteUser.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    onSuccess={() => {
                        toast.success('Invitation sent');
                        setOpen(false);
                    }}
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="space-y-4 py-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        placeholder="user@example.com"
                                        required
                                    />
                                    <InputError message={errors.email} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="role">Role</Label>
                                    <Select name="role" defaultValue="member">
                                        <SelectTrigger id="role">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {availableRoles.map((role) => (
                                                <SelectItem
                                                    key={role}
                                                    value={role}
                                                >
                                                    <span className="flex items-center gap-2">
                                                        {roleIcon(role)}
                                                        <span className="capitalize">
                                                            {role}
                                                        </span>
                                                    </span>
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.role} />
                                </div>
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? 'Sending...'
                                        : 'Send invitation'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function MembersTable({
    members,
    canManage,
    isOwner,
    availableRoles,
    onUpdateRole,
    onRemove,
    onTransfer,
}: {
    members: Member[];
    canManage: boolean;
    isOwner: boolean;
    availableRoles: string[];
    onUpdateRole: (memberId: string, role: string) => void;
    onRemove: (member: Member) => void;
    onTransfer: (member: Member) => void;
}) {
    return (
        <div className="rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Member</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Joined</TableHead>
                        {canManage && (
                            <TableHead className="w-12 text-right">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        )}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {members.map((member) => (
                        <TableRow key={member.id}>
                            <TableCell>
                                <div className="flex items-center gap-3">
                                    <Avatar className="size-8">
                                        <AvatarImage
                                            src={member.avatar}
                                            alt={member.name}
                                        />
                                        <AvatarFallback>
                                            {member.name.charAt(0)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {member.name}
                                        </p>
                                        <p className="truncate text-sm text-muted-foreground">
                                            {member.email}
                                        </p>
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell>
                                <Badge variant={roleBadgeVariant(member.role)}>
                                    <span className="flex items-center gap-1">
                                        {roleIcon(member.role)}
                                        <span className="capitalize">
                                            {member.role}
                                        </span>
                                    </span>
                                </Badge>
                            </TableCell>
                            <TableCell className="text-muted-foreground">
                                {new Date(
                                    member.created_at,
                                ).toLocaleDateString()}
                            </TableCell>
                            {canManage && (
                                <TableCell className="text-right">
                                    {!member.is_owner && (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                >
                                                    <MoreVertical className="size-4" />
                                                    <span className="sr-only">
                                                        Member actions
                                                    </span>
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {availableRoles
                                                    .filter(
                                                        (role) =>
                                                            role !==
                                                                member.role &&
                                                            role !== 'owner',
                                                    )
                                                    .map((role) => (
                                                        <DropdownMenuItem
                                                            key={role}
                                                            onClick={() =>
                                                                onUpdateRole(
                                                                    member.id,
                                                                    role,
                                                                )
                                                            }
                                                        >
                                                            {roleIcon(role)}
                                                            <span>
                                                                Make {role}
                                                            </span>
                                                        </DropdownMenuItem>
                                                    ))}
                                                {isOwner && (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            onTransfer(member)
                                                        }
                                                    >
                                                        <Crown className="size-4 text-yellow-600" />
                                                        <span>
                                                            Transfer ownership
                                                        </span>
                                                    </DropdownMenuItem>
                                                )}
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onClick={() =>
                                                        onRemove(member)
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                    Remove
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    )}
                                </TableCell>
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function PendingInvitationsTable({
    invitations,
    canManage,
    onCancel,
}: {
    invitations: Invitation[];
    canManage: boolean;
    onCancel: (id: string) => void;
}) {
    if (invitations.length === 0) {
        return null;
    }

    return (
        <div className="space-y-4">
            <Heading
                variant="small"
                title="Pending invitations"
                description="Invitations that haven't been accepted yet"
            />
            <div className="rounded-md border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Email</TableHead>
                            <TableHead>Role</TableHead>
                            <TableHead>Invited by</TableHead>
                            <TableHead>Expires</TableHead>
                            {canManage && (
                                <TableHead className="w-12 text-right">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            )}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {invitations.map((invitation) => (
                            <TableRow key={invitation.id}>
                                <TableCell>
                                    <div className="flex items-center gap-2">
                                        <Mail className="size-4 text-muted-foreground" />
                                        <span>{invitation.email}</span>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge variant="outline">
                                        <span className="flex items-center gap-1">
                                            {roleIcon(invitation.role)}
                                            <span className="capitalize">
                                                {invitation.role}
                                            </span>
                                        </span>
                                    </Badge>
                                </TableCell>
                                <TableCell className="text-muted-foreground">
                                    {invitation.invited_by ?? '—'}
                                </TableCell>
                                <TableCell className="text-muted-foreground">
                                    {new Date(
                                        invitation.expires_at,
                                    ).toLocaleDateString()}
                                </TableCell>
                                {canManage && (
                                    <TableCell className="text-right">
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            onClick={() =>
                                                onCancel(invitation.id)
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                            <span className="sr-only">
                                                Cancel invitation
                                            </span>
                                        </Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}

function RemoveMemberDialog({
    member,
    onClose,
    onConfirm,
}: {
    member: Member | null;
    onClose: () => void;
    onConfirm: () => void;
}) {
    return (
        <Dialog
            open={member !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Remove member</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to remove {member?.name} from this
                        workspace? This action cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={onConfirm}
                    >
                        Remove member
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function TransferOwnershipDialog({
    member,
    onClose,
    onConfirm,
}: {
    member: Member | null;
    onClose: () => void;
    onConfirm: () => void;
}) {
    return (
        <Dialog
            open={member !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Transfer ownership</DialogTitle>
                    <DialogDescription>
                        Make {member?.name} the owner of this workspace? You
                        will be demoted to admin and lose owner permissions.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="button" onClick={onConfirm}>
                        Transfer ownership
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function WorkspaceMembers({
    members,
    pendingInvitations,
    canManage,
    availableRoles,
}: Props) {
    const { workspaces } = usePage().props;
    const isOwner = workspaces.current?.role === 'owner';
    const workspaceId = workspaces.current?.id ?? '';

    const [memberToRemove, setMemberToRemove] = useState<Member | null>(null);
    const [memberToPromote, setMemberToPromote] = useState<Member | null>(null);

    const handleUpdateRole = (memberId: string, role: string) => {
        router.patch(
            WorkspaceSettingsController.updateMemberRole.url(memberId),
            { role },
            {
                preserveScroll: true,
                optimistic: (props) => ({
                    members: replaceById(
                        (props as { members?: Member[] }).members,
                        memberId,
                        (member) => ({ ...member, role }),
                    ),
                }),
                onSuccess: () => toast.success('Member role updated'),
            },
        );
    };

    const confirmRemove = () => {
        if (!memberToRemove) {
            return;
        }

        const removedId = memberToRemove.id;

        router.delete(WorkspaceSettingsController.removeMember.url(removedId), {
            preserveScroll: true,
            optimistic: (props) => ({
                members: removeById(
                    (props as { members?: Member[] }).members,
                    removedId,
                ),
            }),
            onSuccess: () => {
                toast.success('Member removed');
                setMemberToRemove(null);
            },
            onError: () => setMemberToRemove(null),
        });
    };

    const confirmTransfer = () => {
        if (!memberToPromote) {
            return;
        }

        router.post(
            WorkspaceController.transferOwnership.url(workspaceId),
            { membership_id: memberToPromote.id },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Ownership transferred');
                    setMemberToPromote(null);
                },
                onError: () => setMemberToPromote(null),
            },
        );
    };

    const handleCancelInvitation = (invitationId: string) => {
        router.delete(
            WorkspaceSettingsController.cancelInvitation.url(invitationId),
            {
                preserveScroll: true,
                optimistic: (props) => ({
                    pendingInvitations: removeById(
                        (props as { pendingInvitations?: Invitation[] })
                            .pendingInvitations,
                        invitationId,
                    ),
                }),
                onSuccess: () => toast.success('Invitation cancelled'),
            },
        );
    };

    return (
        <>
            <Head title="Workspace members" />

            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Members"
                        description="Manage workspace members and their roles"
                    />
                    {canManage && (
                        <InviteMemberDialog availableRoles={availableRoles} />
                    )}
                </div>

                <Deferred data="members" fallback={<MembersSkeleton />}>
                    <MembersTable
                        members={members ?? []}
                        canManage={canManage}
                        isOwner={isOwner}
                        availableRoles={availableRoles}
                        onUpdateRole={handleUpdateRole}
                        onRemove={setMemberToRemove}
                        onTransfer={setMemberToPromote}
                    />
                </Deferred>

                <PendingInvitationsTable
                    invitations={pendingInvitations}
                    canManage={canManage}
                    onCancel={handleCancelInvitation}
                />
            </div>

            <RemoveMemberDialog
                member={memberToRemove}
                onClose={() => setMemberToRemove(null)}
                onConfirm={confirmRemove}
            />

            <TransferOwnershipDialog
                member={memberToPromote}
                onClose={() => setMemberToPromote(null)}
                onConfirm={confirmTransfer}
            />
        </>
    );
}

WorkspaceMembers.layout = {
    breadcrumbs: [
        {
            title: 'Workspace settings',
            href: WorkspaceSettingsController.showOverview().url,
        },
        {
            title: 'Members',
            href: WorkspaceSettingsController.showMembers().url,
        },
    ],
};
