import { Trans } from "@lingui/react/macro";
import { useNavigate, useSearchParams } from "react-router-dom";
import { usePermissions } from "../../logic/usePermissions";
import ManagementProjectsBoard from "./ManagementProjectsBoard";
import PhotographerProductionBoard from "../photographer/PhotographerProductionBoard";

export default function ManagementBoardsView() {
    const { isSuperAdmin } = usePermissions();
    const [searchParams] = useSearchParams();
    const navigate = useNavigate();
    const tab = searchParams.get("tab") ?? "projects";

    const selectTab = (next: string) => {
        const params = new URLSearchParams(searchParams);
        params.set("tab", next);
        navigate(`/boards?${params.toString()}`, { replace: true });
    };

    const isProjects = tab === "projects";

    return (
        <div className="p-6 md:p-10 max-w-screen-2xl mx-auto w-full">
            {isSuperAdmin && (
                <div className="mb-6">
                    <div className="flex items-center gap-2 mb-3">
                        <span className="iconify mdi--view-column text-2xl text-primary"></span>
                        <h1 className="text-2xl font-bold"><Trans>Workflow</Trans></h1>
                    </div>
                    <div className="grid grid-cols-2 gap-1 p-1 bg-base-200 border border-base-300 rounded-box shadow-sm">
                        <a
                            className={`btn justify-center gap-2 border-0 ${isProjects ? "btn-primary" : "btn-ghost"}`}
                            aria-pressed={isProjects}
                            onClick={() => selectTab("projects")}
                        >
                            <span className="iconify mdi--briefcase-outline text-lg"></span>
                            <Trans>Projekte</Trans>
                        </a>
                        <a
                            className={`btn justify-center gap-2 border-0 ${!isProjects ? "btn-primary" : "btn-ghost"}`}
                            aria-pressed={!isProjects}
                            onClick={() => selectTab("production")}
                        >
                            <span className="iconify mdi--image-edit text-lg"></span>
                            <Trans>Bildbearbeitung</Trans>
                        </a>
                    </div>
                </div>
            )}
            {isProjects ? (
                <ManagementProjectsBoard embedded />
            ) : (
                <PhotographerProductionBoard embedded />
            )}
        </div>
    );
}