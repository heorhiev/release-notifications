#!/usr/bin/env node

import { createInterface } from 'node:readline';

const apiBaseUrl = (process.env.JIRA_RELEASE_REPORT_API_URL || 'http://localhost:8082').replace(/\/+$/, '');
const requestTimeoutMs = Number.parseInt(process.env.JIRA_RELEASE_REPORT_TIMEOUT_MS || '90000', 10);

const reportInputSchema = {
  type: 'object',
  properties: {
    release: {
      type: 'string',
      minLength: 1,
      description: 'Exact Jira release / fixVersion name, including spaces.',
    },
    latest_release: {
      type: 'boolean',
      description: 'Select the latest non-archived Jira release. Do not combine with release.',
    },
  },
  additionalProperties: false,
};

const tools = [
  {
    name: 'jira_release_report_health',
    description: 'Check whether the Jira Release Report service is available.',
    inputSchema: { type: 'object', properties: {}, additionalProperties: false },
    annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: true },
  },
  {
    name: 'build_jira_release_report',
    description: 'Build and store a Jira release report without sending it to Slack.',
    inputSchema: reportInputSchema,
    annotations: { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
  },
  {
    name: 'send_jira_release_report',
    description: 'Build, store, and send a Jira release report to Slack.',
    inputSchema: reportInputSchema,
    annotations: { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
  },
];

function response(id, result) {
  process.stdout.write(`${JSON.stringify({ jsonrpc: '2.0', id, result })}\n`);
}

function errorResponse(id, code, message, data) {
  const error = { code, message };
  if (data !== undefined) error.data = data;
  process.stdout.write(`${JSON.stringify({ jsonrpc: '2.0', id, error })}\n`);
}

async function requestJson(path, options = {}) {
  const timeoutMs = Number.isFinite(requestTimeoutMs) ? requestTimeoutMs : 90000;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const httpResponse = await fetch(`${apiBaseUrl}${path}`, {
      ...options,
      headers: { Accept: 'application/json', ...(options.headers || {}) },
      signal: controller.signal,
    });
    const text = await httpResponse.text();
    let payload;
    try {
      payload = text === '' ? {} : JSON.parse(text);
    } catch {
      throw new Error(`Service returned invalid JSON (HTTP ${httpResponse.status}): ${text}`);
    }

    if (!httpResponse.ok) {
      throw new Error(payload.error || `Service returned HTTP ${httpResponse.status}`);
    }

    return payload;
  } catch (error) {
    if (error?.name === 'AbortError') {
      throw new Error(`Jira Release Report request timed out after ${timeoutMs} ms`);
    }
    throw error;
  } finally {
    clearTimeout(timeout);
  }
}

function validateReportArgs(args) {
  const release = typeof args?.release === 'string' ? args.release.trim() : '';
  const latestRelease = args?.latest_release === true;
  if (release !== '' && latestRelease) {
    throw new Error('Use either release or latest_release, not both.');
  }
  return { release, latestRelease };
}

async function callTool(name, args) {
  if (name === 'jira_release_report_health') {
    return toolResult(await requestJson('/health'));
  }

  if (name !== 'build_jira_release_report' && name !== 'send_jira_release_report') {
    throw new Error(`Unknown tool: ${name}`);
  }

  const { release, latestRelease } = validateReportArgs(args);
  const body = {
    dry_run: name === 'build_jira_release_report',
  };
  if (release !== '') {
    body.release = release;
  } else {
    body.latest_release = latestRelease || release === '';
  }

  const payload = await requestJson('/release-report', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });

  return toolResult(payload);
}

function toolResult(payload) {
  return {
    content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }],
    structuredContent: payload,
  };
}

async function handle(message) {
  if (!message || message.jsonrpc !== '2.0') return;
  if (message.method === 'notifications/initialized' || message.method === 'notifications/cancelled') return;

  if (message.method === 'initialize') {
    response(message.id, {
      protocolVersion: message.params?.protocolVersion || '2025-03-26',
      capabilities: { tools: {} },
      serverInfo: { name: 'jira-release-report', version: '0.1.0' },
    });
    return;
  }

  if (message.method === 'ping') {
    response(message.id, {});
    return;
  }

  if (message.method === 'tools/list') {
    response(message.id, { tools });
    return;
  }

  if (message.method === 'tools/call') {
    try {
      response(message.id, await callTool(message.params?.name, message.params?.arguments || {}));
    } catch (error) {
      response(message.id, {
        content: [{ type: 'text', text: error instanceof Error ? error.message : String(error) }],
        isError: true,
      });
    }
    return;
  }

  if (message.id !== undefined) errorResponse(message.id, -32601, `Method not found: ${message.method}`);
}

const input = createInterface({ input: process.stdin, terminal: false });
input.on('line', async (line) => {
  if (line.trim() === '') return;
  try {
    await handle(JSON.parse(line));
  } catch (error) {
    errorResponse(null, -32700, 'Parse error', error instanceof Error ? error.message : String(error));
  }
});
