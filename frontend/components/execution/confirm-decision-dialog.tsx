"use client";

import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Button } from "@/components/ui/button";

export function ConfirmDecisionDialog({
  open,
  action,
  requestName,
  stepLabel,
  pending,
  onOpenChange,
  onConfirm,
}: {
  open: boolean;
  action: "approve" | "reject" | null;
  requestName: string;
  stepLabel: string;
  pending: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
}) {
  const approving = action === "approve";
  const verb = approving ? "Approve" : "Reject";

  return (
    <AlertDialog open={open} onOpenChange={(next) => (pending ? undefined : onOpenChange(next))}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>
            {verb} “{requestName}”?
          </AlertDialogTitle>
          <AlertDialogDescription>
            This will {approving ? "approve" : "reject"} the request “{requestName}” at the {stepLabel} step. FlowMV records
            the decision and advances the workflow.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>Cancel</AlertDialogCancel>
          <Button
            type="button"
            className="h-10"
            variant={approving ? "default" : "destructive"}
            disabled={pending || action === null}
            onClick={onConfirm}
          >
            {pending ? "Saving" : verb}
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
