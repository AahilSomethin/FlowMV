"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from "@/components/ui/breadcrumb";
import { workspaceNavItem } from "@/lib/navigation";

export function AppBreadcrumbs() {
  const pathname = usePathname();
  const current = workspaceNavItem(pathname);
  const nested = current && pathname !== current.href;
  const detailLabel = pathname === "/requests/new" ? "New request" : pathname.endsWith("/edit") ? "Workflow studio" : pathname === "/analytics/audit" ? "Audit" : current?.href === "/tasks" ? "Task details" : current?.href === "/requests" ? "Request details" : "Workflow details";

  if (!current || current.href === "/dashboard") {
    return (
      <Breadcrumb className="min-w-0">
        <BreadcrumbList className="flex-nowrap">
          <BreadcrumbItem>
            <BreadcrumbPage className="truncate">Overview</BreadcrumbPage>
          </BreadcrumbItem>
        </BreadcrumbList>
      </Breadcrumb>
    );
  }

  return (
    <Breadcrumb className="min-w-0">
      <BreadcrumbList className="flex-nowrap">
        <BreadcrumbItem className="hidden sm:inline-flex">
          <BreadcrumbLink asChild>
            <Link href="/dashboard">Overview</Link>
          </BreadcrumbLink>
        </BreadcrumbItem>
        <BreadcrumbSeparator className="hidden sm:block" />
        <BreadcrumbItem className="min-w-0">
          {nested ? <BreadcrumbLink asChild><Link href={current.href}>{current.label}</Link></BreadcrumbLink> : <BreadcrumbPage className="truncate">{current.label}</BreadcrumbPage>}
        </BreadcrumbItem>
        {nested ? <><BreadcrumbSeparator /><BreadcrumbItem className="min-w-0"><BreadcrumbPage className="truncate">{detailLabel}</BreadcrumbPage></BreadcrumbItem></> : null}
      </BreadcrumbList>
    </Breadcrumb>
  );
}
