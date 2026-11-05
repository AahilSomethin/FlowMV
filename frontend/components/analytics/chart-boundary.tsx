"use client";

import { Component, type ReactNode } from "react";

export class ChartBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
 state = { failed: false };
 static getDerivedStateFromError() { return { failed: true }; }
 render() {
  if (this.state.failed) return <p role="alert" className="text-sm text-muted-foreground">The chart could not load. Your report remains available below. Reload this page to try again.</p>;
  return this.props.children;
 }
}
