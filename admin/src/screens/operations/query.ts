/**
 * What the operator has narrowed the trail to, before it becomes a request.
 *
 * Kept apart from the controls that edit it so the shape can be shared without the
 * component, and so an empty query has one definition rather than one per caller.
 */
export interface AuditQuery {
    action: string;
    subject: string;
    actorId: string;
    outcome: string;
    /** `datetime-local` wall time; converted to an instant where it is sent. */
    from: string;
    to: string;
}

export const EMPTY_QUERY: AuditQuery = {
    action: '',
    subject: '',
    actorId: '',
    outcome: '',
    from: '',
    to: '',
};
