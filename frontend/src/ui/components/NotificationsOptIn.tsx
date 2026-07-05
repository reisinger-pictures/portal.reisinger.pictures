import { Trans } from "@lingui/react/macro";

interface NotificationsOptInProps {
    checked: boolean;
    onChange: (checked: boolean) => void;
}

export default function NotificationsOptIn({ checked, onChange }: NotificationsOptInProps) {
    return (
        <label className="cursor-pointer label gap-2 md:gap-3 bg-base-100 p-2 md:p-3 rounded-box border border-base-300 shadow-sm">
            <span className="iconify mdi--bell-ring-outline text-xl text-primary hidden md:inline-block"></span>
            <div className="text-right">
                <span className="font-bold text-sm block leading-none mb-1"><Trans>E-Mail Updates</Trans></span>
            </div>
            <input type="checkbox" className="toggle-primary toggle md:toggle-md" checked={checked} onChange={e => onChange(e.target.checked)} />
        </label>
    );
}
