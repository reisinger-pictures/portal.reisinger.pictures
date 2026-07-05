import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useState } from 'react';
import { apiMutate } from '../../../api';
import { useUI } from '../../components/UIContext';
import WysiwygEditor from '../../components/WysiwygEditor';
import { SendMailResponse } from '../../../api';

interface EmailComposerModalProps {
    isOpen: boolean;
    onClose: () => void;
    galleryId: string;
}

export default function EmailComposerModal({ isOpen, onClose, galleryId }: EmailComposerModalProps) {
    const [mailSubject, setMailSubject] = useState('Neuigkeiten in deiner Galerie: {gallery_name}');
    const [mailBody, setMailBody] = useState('<p>Hallo {user_name},</p><p>Es gibt Neuigkeiten in deiner Galerie <strong>{gallery_name}</strong>.</p><p><a href="{link}">Hier geht es zur Galerie</a></p>');
    const [sendingMail, setSendingMail] = useState(false);
    const { showToast } = useUI();

    if (!isOpen) return null;

    const handleSendCustomMail = async () => {
        if (!galleryId || !mailSubject || !mailBody) return;
        setSendingMail(true);
        try {
            const data = await apiMutate<SendMailResponse>(
                `/api/management/galleries/${galleryId}/send-custom-email`, 'POST', 
                { subject: mailSubject, body: mailBody }
            );
            const notifiedCount = data.notified_count;
            showToast('success', t`Erfolg! ${notifiedCount} E-Mails versendet.`);
            onClose();
        } catch (err: unknown) {
            const emailError = err instanceof Error ? err.message : 'Unbekannter Fehler';
            showToast('error', t`Fehler: ${emailError}`);
        }
        setSendingMail(false);
    };

    return (
        <div className="modal modal-open z-50">
            <div className="modal-box max-w-3xl relative">
                <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-4"><Trans>Nachricht an Kunden senden</Trans></h3>

                <div className="form-control mb-4">
                    <label className="label"><span className="label-text font-bold"><Trans>Betreff</Trans></span></label>
                    <input type="text" value={mailSubject} onChange={e => setMailSubject(e.target.value)} className="input input-bordered w-full"/>
                </div>

                <div className="form-control mb-6">
                    <div className="flex justify-between items-end mb-2">
                        <label className="label p-0"><span className="label-text font-bold"><Trans>Nachricht</Trans></span></label>
                    </div>
                    <span className="label-text-alt opacity-70 whitespace-normal break-words leading-tight inline-block mb-2">Variablen: {"{user_name}"}, {"{gallery_name}"}, {"{link}"}</span>
                    
                    <WysiwygEditor value={mailBody} onChange={setMailBody} />
                </div>

                <div className="modal-action col-span-full">
                    <button className="btn btn-ghost" onClick={onClose}><Trans>Abbrechen</Trans></button>
                    <button className="btn btn-primary" disabled={sendingMail || !mailSubject || !mailBody} onClick={handleSendCustomMail}>
                        {sendingMail ? <span className="loading loading-spinner"></span> : <Trans>Nachricht Senden</Trans>}
                    </button>
                </div>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}