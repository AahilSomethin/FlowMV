"use client";

import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import { EmptyState } from "@/components/patterns/empty-state";
import { ErrorState } from "@/components/patterns/error-state";
import { LoadingState } from "@/components/patterns/loading-state";
import { PageHeader } from "@/components/patterns/page-header";
import { InviteMemberDialog } from "@/components/team/invite-member-dialog";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { api, ApiError, type OrganizationRole } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { roleLabel, type Invitation, type Member } from "@/lib/organizations";

export function TeamScreen() {
  const { user, refresh } = useAuth();
  const current = user?.current_organization ?? null;
  const organizationId = current?.uuid;
  const role = current?.role;
  const [members, setMembers] = useState<Member[]>([]);
  const [invitations, setInvitations] = useState<Invitation[]>([]);
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [denied, setDenied] = useState(false);
  const [pendingRole, setPendingRole] = useState<string | null>(null);
  const [pendingRevoke, setPendingRevoke] = useState<string | null>(null);
  const [revokeTarget, setRevokeTarget] = useState<Member | null>(null);

  useEffect(() => {
    if (!organizationId) {
      return;
    }

    let active = true;

    async function load() {
      try {
        const nextMembers = await api<Member[]>(`/api/v1/organizations/${organizationId}/members`);
        let nextInvitations: Invitation[] = [];
        let nextDenied = false;

        if (role === "admin") {
          try {
            nextInvitations = await api<Invitation[]>(`/api/v1/organizations/${organizationId}/invitations`);
          } catch (caught) {
            if (!(caught instanceof ApiError) || caught.status !== 403) {
              throw caught;
            }

            nextDenied = true;
            await refresh();
          }
        }

        if (!active) {
          return;
        }

        setDenied(nextDenied);
        setError(null);
        setMembers(nextMembers);
        setInvitations(nextInvitations);
        setLoadedKey(`${organizationId}:${role ?? ""}`);
      } catch (caught) {
        if (!active) {
          return;
        }

        if (caught instanceof ApiError && (caught.status === 403 || caught.status === 404)) {
          setDenied(caught.status === 403);
          setError(caught.status === 404 ? "This workspace is no longer available." : "You do not have access to this team.");
          await refresh().catch(() => undefined);
        } else {
          setDenied(false);
          setError(caught instanceof ApiError ? caught.message : "Could not load the team.");
        }

        setMembers([]);
        setInvitations([]);
        setLoadedKey(`${organizationId}:${role ?? ""}`);
      }
    }

    void load();

    return () => {
      active = false;
    };
  }, [organizationId, refresh, role]);

  const requestKey = organizationId ? `${organizationId}:${role ?? ""}` : null;
  const currentData = requestKey !== null && loadedKey === requestKey;
  const loading = requestKey !== null && !currentData;
  const shownMembers = currentData ? members : [];
  const shownInvitations = currentData ? invitations : [];
  const shownError = currentData ? error : null;
  const shownDenied = currentData && denied;
  const isAdmin = current?.role === "admin" && !shownDenied;

  async function changeRole(member: Member, role: OrganizationRole) {
    if (!current || role === member.role) {
      return;
    }

    setError(null);
    setPendingRole(member.uuid);

    try {
      const updated = await api<Member>(`/api/v1/organizations/${current.uuid}/members/${member.uuid}`, {
        method: "PATCH",
        body: JSON.stringify({ role }),
      });
      setMembers((existing) => existing.map((item) => (item.uuid === updated.uuid ? updated : item)));
      if (member.email === user?.email) {
        await refresh();
      }
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 403) {
        setDenied(true);
        setError("You do not have permission to change roles.");
        await refresh().catch(() => undefined);
      } else {
        setError(caught instanceof ApiError ? caught.message : "Could not change that role.");
      }
    } finally {
      setPendingRole(null);
    }
  }

  async function revoke(member: Member) {
    if (!current) {
      return;
    }

    setError(null);
    setPendingRevoke(member.uuid);

    try {
      await api<void>(`/api/v1/organizations/${current.uuid}/members/${member.uuid}`, { method: "DELETE" });
      setMembers((existing) => existing.filter((item) => item.uuid !== member.uuid));
      setRevokeTarget(null);
      if (member.email === user?.email) {
        await refresh();
      }
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 403) {
        setDenied(true);
        setError("You do not have permission to remove members.");
        await refresh().catch(() => undefined);
      } else {
        setError(caught instanceof ApiError ? caught.message : "Could not remove that member.");
      }
    } finally {
      setPendingRevoke(null);
    }
  }

  async function cancelInvitation(invitation: Invitation) {
    if (!current) {
      return;
    }

    setError(null);

    try {
      await api<void>(`/api/v1/organizations/${current.uuid}/invitations/${invitation.uuid}`, {
        method: "DELETE",
      });
      setInvitations((existing) => existing.filter((item) => item.uuid !== invitation.uuid));
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not cancel that invitation.");
    }
  }

  return (
    <>
      {!current ? (
        <Card>
          <CardHeader>
            <CardTitle>Choose a workspace</CardTitle>
            <CardDescription>Select an organization in the header to see its team.</CardDescription>
          </CardHeader>
        </Card>
      ) : (
        <>
          <PageHeader
            title="Team"
            description={`${current.name}. Your role is loaded from the server for this workspace.`}
            actions={
              isAdmin ? (
                <InviteMemberDialog
                  organization={current}
                  onInvited={(invitation) => setInvitations((existing) => [...existing, invitation])}
                />
              ) : null
            }
          />
          {shownError ? (
            <ErrorState title={shownDenied ? "Permission denied" : "Something went wrong"} description={shownError} />
          ) : null}
          {!isAdmin ? (
            <Alert>
              <AlertTitle>View only</AlertTitle>
              <AlertDescription>You can view this team. Only admins can invite people or change roles.</AlertDescription>
            </Alert>
          ) : null}
          <Card>
            <CardHeader>
              <CardTitle>Members</CardTitle>
              <CardDescription>
                {loading ? "Loading members" : shownMembers.length === 0 ? "No active members." : `${shownMembers.length} active`}
              </CardDescription>
            </CardHeader>
            <CardContent>
              {loading ? (
                <LoadingState label="Loading the team" />
              ) : shownMembers.length === 0 ? (
                <EmptyState title="No active members" description="People who join this workspace will be listed here." />
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Name</TableHead>
                      <TableHead>Email</TableHead>
                      <TableHead>Role</TableHead>
                      {isAdmin ? <TableHead className="text-right">Access</TableHead> : null}
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {shownMembers.map((member) => (
                      <TableRow key={member.uuid}>
                        <TableCell className="font-medium">{member.name}</TableCell>
                        <TableCell className="max-w-48 truncate">{member.email}</TableCell>
                        <TableCell>
                          {isAdmin ? (
                            <Select
                              value={member.role}
                              onValueChange={(role) => {
                                void changeRole(member, role as OrganizationRole);
                              }}
                              disabled={pendingRole === member.uuid}
                            >
                              <SelectTrigger className="h-10 w-full min-w-0 sm:w-36" aria-label={`Role for ${member.name}`}>
                                <SelectValue />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="admin">{roleLabel("admin")}</SelectItem>
                                <SelectItem value="manager">{roleLabel("manager")}</SelectItem>
                                <SelectItem value="employee">{roleLabel("employee")}</SelectItem>
                              </SelectContent>
                            </Select>
                          ) : (
                            <Badge variant="outline">{roleLabel(member.role)}</Badge>
                          )}
                        </TableCell>
                        {isAdmin ? (
                          <TableCell className="text-right">
                            <Button
                              type="button"
                              variant="destructive"
                              className="h-10"
                              onClick={() => setRevokeTarget(member)}
                            >
                              Remove
                            </Button>
                          </TableCell>
                        ) : null}
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>
          {isAdmin ? (
            <Card>
              <CardHeader>
                <CardTitle>Pending invitations</CardTitle>
                <CardDescription>
                  {shownInvitations.length === 0 ? "No pending invitations." : "These invitations have not been accepted."}
                </CardDescription>
              </CardHeader>
              {shownInvitations.length > 0 ? (
                <CardContent>
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Email</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Expires</TableHead>
                        <TableHead className="text-right">Invitation</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {shownInvitations.map((invitation) => (
                        <TableRow key={invitation.uuid}>
                          <TableCell className="max-w-48 truncate">{invitation.email}</TableCell>
                          <TableCell>
                            <Badge variant="outline">{roleLabel(invitation.role)}</Badge>
                          </TableCell>
                          <TableCell>
                            {invitation.expires_at ? new Date(invitation.expires_at).toLocaleDateString() : "—"}
                          </TableCell>
                          <TableCell className="text-right">
                            <Button type="button" variant="outline" className="h-10" onClick={() => void cancelInvitation(invitation)}>
                              Cancel
                            </Button>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </CardContent>
              ) : null}
            </Card>
          ) : null}
          <Dialog open={revokeTarget !== null} onOpenChange={(open) => !open && setRevokeTarget(null)}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-md">
              <DialogHeader>
                <DialogTitle>Remove member</DialogTitle>
                <DialogDescription>
                  {revokeTarget ? `${revokeTarget.name} will lose access to ${current.name} immediately.` : ""}
                </DialogDescription>
              </DialogHeader>
              <DialogFooter>
                <Button type="button" variant="outline" className="h-10" onClick={() => setRevokeTarget(null)}>
                  Keep member
                </Button>
                <Button
                  type="button"
                  variant="destructive"
                  className="h-10"
                  disabled={pendingRevoke !== null}
                  onClick={() => revokeTarget && void revoke(revokeTarget)}
                >
                  {pendingRevoke ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
                  Remove
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </>
      )}
    </>
  );
}
