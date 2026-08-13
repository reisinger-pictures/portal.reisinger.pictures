import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import LicenseCatalogSettings from './LicenseCatalogSettings';
import VolumePresetSettingsCard from './VolumePresetSettingsCard';

type PricingTab = 'licenses' | 'volume';

/**
 * Gruppiert Lizenz-Katalog (Scope) und Volume-Licensing-Presets als zwei Tabs.
 *
 * Tab-Leiste (`tabs-box`, radio-gesteuert) und Inhalt teilen sich EINE Karte:
 * Die Tabs sitzen als Segment-Control im Kartenkopf, der Inhalt fließt ohne
 * eigenen Rand direkt darunter — dadurch entsteht keine sichtbare Naht und
 * keine doppelte Linie zwischen Tab und Content.
 */
export default function PricingSettingsTabs() {
    const [activeTab, setActiveTab] = useState<PricingTab>('licenses');

    return (
        <section className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-4 sm:p-6 md:p-8">
                <div role="tablist" aria-label={t`Preise & Lizenzen`} className="tabs tabs-box w-full">
                    <label className="tab">
                        <input
                            type="radio"
                            name="pricing_tabs"
                            checked={activeTab === 'licenses'}
                            onChange={() => setActiveTab('licenses')}
                        />
                        <span className="iconify mdi--format-list-checks text-primary mr-1"></span>
                        <Trans>Lizenz-Katalog</Trans>
                    </label>
                    <label className="tab">
                        <input
                            type="radio"
                            name="pricing_tabs"
                            checked={activeTab === 'volume'}
                            onChange={() => setActiveTab('volume')}
                        />
                        <span className="iconify mdi--chart-gantt text-primary mr-1"></span>
                        <Trans>Volume-Pricing</Trans>
                    </label>
                </div>

                <div role="tabpanel" className="mt-4 sm:mt-6">
                    {activeTab === 'licenses' ? <LicenseCatalogSettings /> : <VolumePresetSettingsCard />}
                </div>
            </div>
        </section>
    );
}
