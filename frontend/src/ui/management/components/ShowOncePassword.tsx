import { Trans } from "@lingui/react/macro";

/**
 * The show-once password.
 *
 * Held in the caller's component state and nowhere else: no SWR cache, no
 * storage, no navigation state. Unmounting the owning card — closing the view or
 * navigating away — drops the value and its DOM node with it, so there is
 * nothing to clean up by hand and nothing that can survive into the next mount.
 * The dismiss button removes it while the card stays open, which is the only way
 * a password could otherwise linger on a shared screen.
 *
 * Shared deliberately: a slug change is a password reset (`FtpCredentialService`),
 * so the profile form and the FTP inbox receive the same one-time secret and must
 * present it with the same warning. A second, drifting copy of this markup is
 * exactly how one of the two paths would end up without the "shown once" notice.
 */
export default function ShowOncePassword({password, notice, onDismiss}: {password: string; notice: string; onDismiss: () => void}) {
    return (
        <div className="alert alert-warning shadow-sm mt-3" role="status">
            <span className="iconify mdi--key-outline text-xl"></span>
            <div className="flex-1">
                <h3 className="font-bold"><Trans>Neues Kamera-Passwort</Trans></h3>
                <code className="font-mono text-lg font-bold select-all break-all">{password}</code>
                <p className="text-sm mt-1">{notice}</p>
                <button className="btn btn-xs btn-outline mt-2" onClick={onDismiss}><Trans>Verstanden, ausblenden</Trans></button>
            </div>
        </div>
    );
}
