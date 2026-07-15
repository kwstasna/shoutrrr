import { Head, router } from '@inertiajs/react';
import {
    Check,
    Copy,
    Link2,
    RefreshCw,
    Trash2,
    Webhook,
    Zap,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import WebhooksController from '@/actions/App/Http/Controllers/Settings/WebhooksController';
import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import { useConfirm } from '@/components/common/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dayjs } from '@/lib/datetime/dayjs';

type WebhookConfig = {
    endpoint_token: string;
    callback_url: string;
    verify_token: string;
    has_custom_secret: boolean;
    last_received_at: string | null;
    last_event: string | null;
    received_count: number;
};

type Props = {
    webhook: WebhookConfig | null;
    globalSecretConfigured: boolean;
};

function CopyField({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);

    async function copy() {
        if (!navigator.clipboard) {
            toast.error(
                'Copy is unavailable here — select the value manually.',
            );
            return;
        }
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            toast.success('Copied to clipboard');
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error('Copy failed — select the value manually.');
        }
    }

    return (
        <div className="space-y-1.5">
            <Label>{label}</Label>
            <div className="flex items-center gap-2">
                <Input
                    readOnly
                    value={value}
                    aria-label={label}
                    onFocus={(event) => event.target.select()}
                    className="font-mono text-xs"
                />
                <Button
                    type="button"
                    variant="outline"
                    className="shrink-0"
                    onClick={copy}
                >
                    {copied ? (
                        <Check className="size-4" />
                    ) : (
                        <Copy className="size-4" />
                    )}
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>
        </div>
    );
}

function SetupGuide() {
    return (
        <ol className="list-decimal space-y-1.5 pl-5 text-sm text-muted-foreground">
            <li>
                Open your app in the{' '}
                <a
                    href="https://developers.facebook.com/apps"
                    target="_blank"
                    rel="noreferrer"
                    className="underline underline-offset-2"
                >
                    Meta App Dashboard
                </a>{' '}
                → Webhooks → Instagram.
            </li>
            <li>
                Paste the <strong>Callback URL</strong> and{' '}
                <strong>Verify token</strong> above and click Verify and save.
            </li>
            <li>
                Subscribe to the <code>story_insights</code>,{' '}
                <code>comments</code>, and <code>messages</code> fields (
                <code>messages</code> carries story replies).
            </li>
            <li>
                Connected Instagram accounts are subscribed automatically. For
                accounts connected earlier, click{' '}
                <strong>Subscribe accounts</strong> above to re-wire them.
            </li>
            <li>
                Story insights arrive when a story expires (within 24h) and feed
                your analytics; comments and story replies land in your
                engagement inbox.
            </li>
        </ol>
    );
}

export default function Webhooks({ webhook, globalSecretConfigured }: Props) {
    const confirm = useConfirm();

    function create() {
        router.post(
            WebhooksController.store().url,
            {},
            { preserveScroll: true },
        );
    }

    function sendTest() {
        router.post(
            WebhooksController.test().url,
            {},
            { preserveScroll: true },
        );
    }

    function subscribeAccounts() {
        router.post(
            WebhooksController.subscribe().url,
            {},
            { preserveScroll: true },
        );
    }

    async function regenerate() {
        const confirmed = await confirm({
            title: 'Regenerate webhook URL?',
            description:
                'The current callback URL and verify token stop working until you update them in the Meta App Dashboard.',
            actionLabel: 'Regenerate',
            destructive: true,
        });
        if (confirmed) {
            router.post(
                WebhooksController.regenerate().url,
                {},
                { preserveScroll: true },
            );
        }
    }

    async function remove() {
        const confirmed = await confirm({
            title: 'Delete webhook?',
            description:
                'Meta deliveries to this URL stop being accepted. You can create a new one later.',
            actionLabel: 'Delete webhook',
            destructive: true,
        });
        if (confirmed) {
            router.delete(WebhooksController.destroy().url, {
                preserveScroll: true,
            });
        }
    }

    return (
        <>
            <Head title="Webhooks" />

            <div className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Webhooks</CardTitle>
                        <CardDescription>
                            Receive Instagram events from Meta — story insights
                            for analytics and comments for your engagement
                            inbox. Each workspace gets its own callback URL.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {webhook === null ? (
                            <Empty className="border border-dashed">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Webhook />
                                    </EmptyMedia>
                                    <EmptyTitle>No webhook yet</EmptyTitle>
                                    <EmptyDescription>
                                        Create a webhook to get a callback URL
                                        and verify token for the Meta App
                                        Dashboard.
                                    </EmptyDescription>
                                </EmptyHeader>
                                <EmptyContent>
                                    <Button onClick={create}>
                                        Create webhook
                                    </Button>
                                </EmptyContent>
                            </Empty>
                        ) : (
                            <>
                                {!globalSecretConfigured &&
                                    !webhook.has_custom_secret && (
                                        <p className="rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400">
                                            No Facebook app secret is
                                            configured, so signatures can&apos;t
                                            be verified. Set{' '}
                                            <code>FACEBOOK_CLIENT_SECRET</code>{' '}
                                            for this instance.
                                        </p>
                                    )}

                                <CopyField
                                    label="Callback URL"
                                    value={webhook.callback_url}
                                />
                                <CopyField
                                    label="Verify token"
                                    value={webhook.verify_token}
                                />

                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        variant="outline"
                                        onClick={sendTest}
                                    >
                                        <Zap className="size-4" />
                                        Test
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={subscribeAccounts}
                                    >
                                        <Link2 className="size-4" />
                                        Subscribe accounts
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={regenerate}
                                    >
                                        <RefreshCw className="size-4" />
                                        Regenerate
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        className="text-destructive hover:text-destructive"
                                        onClick={remove}
                                    >
                                        <Trash2 className="size-4" />
                                        Delete
                                    </Button>
                                </div>

                                <div className="rounded-md border border-border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                                    {webhook.received_count > 0 ? (
                                        <>
                                            Received {webhook.received_count}{' '}
                                            event
                                            {webhook.received_count === 1
                                                ? ''
                                                : 's'}
                                            {webhook.last_received_at && (
                                                <>
                                                    {' · last '}
                                                    {webhook.last_event ??
                                                        'event'}{' '}
                                                    {dayjs(
                                                        webhook.last_received_at,
                                                    ).fromNow()}
                                                </>
                                            )}
                                        </>
                                    ) : (
                                        'No events received yet.'
                                    )}
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>

                {webhook !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Setup guide</CardTitle>
                            <CardDescription>
                                Point Meta at your callback URL to start
                                receiving events.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <SetupGuide />
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

Webhooks.layout = {
    breadcrumbs: [
        {
            title: 'Workspace settings',
            href: WorkspaceSettingsController.showOverview().url,
        },
        {
            title: 'Webhooks',
            href: WebhooksController.index().url,
        },
    ],
};
