/** The refusal a status dialog shows: the first of the fields these actions can fail on. */
export function firstError(errors: Record<string, string>): string | undefined {
    return ['name', 'status', 'into', 'color', 'category'].map((k) => errors[k]).find(Boolean);
}
