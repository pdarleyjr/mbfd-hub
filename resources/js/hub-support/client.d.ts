export function sendIssue(input: {
    description: string;
    attachments: File[];
    clientSubmissionId: string;
}): Promise<{ report: { reference: string } }>;
