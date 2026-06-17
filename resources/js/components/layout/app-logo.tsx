import AppLogoIcon from '@/components/layout/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-6 shrink-0 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                <AppLogoIcon className="size-3.5 fill-current text-white dark:text-black" />
            </div>
            <div className="grid flex-1 text-left">
                <span className="truncate text-[13px] leading-tight font-semibold tracking-tight">
                    Shoutrrr
                </span>
            </div>
        </>
    );
}
