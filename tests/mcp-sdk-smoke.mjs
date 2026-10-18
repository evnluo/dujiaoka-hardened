import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
const root = process.env.MCP_SDK_ROOT;
if (!root) throw new Error('MCP_SDK_ROOT must point to the installed official @modelcontextprotocol/sdk package');
const { Client } = await import(pathToFileURL(`${root}/dist/esm/client/index.js`));
const { StreamableHTTPClientTransport } = await import(pathToFileURL(`${root}/dist/esm/client/streamableHttp.js`));
const { AjvJsonSchemaValidator } = await import(pathToFileURL(`${root}/dist/esm/validation/ajv-provider.js`));
const url = new URL(process.env.MCP_TEST_URL ?? 'http://127.0.0.1:18769/api/admin/mcp');
if (!['127.0.0.1', 'localhost', 'mcp-app'].includes(url.hostname)) throw new Error('Refusing non-test endpoint');
const client = new Client({ name: 'synthetic-shop-smoke', version: '1.0.0' });
const transport = new StreamableHTTPClientTransport(url, { requestInit: { headers: { Authorization: `Bearer ${'ab'.repeat(32)}` } } });
try {
  assert.equal((await fetch(url)).status, 401);
  await client.connect(transport);
  assert.equal(client.getServerVersion().name, 'Shop Admin');
  const tools = []; let cursor;
  do { const page = await client.listTools(cursor ? { cursor } : {}); tools.push(...page.tools); cursor = page.nextCursor; } while (cursor);
  assert.equal(new Set(tools.map(t => t.name)).size, tools.length);
  // SDK 1.x caches only the most recently listed page: validate every collected schema explicitly.
  const validator = new AjvJsonSchemaValidator();
  const outputs = new Map(tools.map(t => [t.name, validator.getValidator(t.outputSchema)]));
  for (const t of tools) { assert.equal(t.inputSchema.additionalProperties, false); assert.ok(t.description.includes('untrusted')); }
  const call = async (name, args = {}) => {
    const r = await client.callTool({ name, arguments: args });
    const data = r.structuredContent ?? JSON.parse(r.content[0].text);
    assert.ok(!r.isError, `${name}: ${JSON.stringify(data)}`);
    assert.ok(r.structuredContent, `${name} must return structured content`);
    const valid = outputs.get(name)(r.structuredContent);
    assert.ok(valid.valid, `${name}: ${valid.errorMessage}`);
    return data;
  };
  let sequence = 0;
  await call('settings_get');
  const mutate = async (name, args) => {
    args = { ...args, reason: 'Synthetic SDK lifecycle' };
    const preview = await call(name, args); assert.equal(preview.dryRun, true);
    const apply = { ...args, dryRun: false, previewToken: preview.previewToken, idempotencyKey: `synthetic-sdk-key-${++sequence}` };
    const first = await call(name, apply); assert.equal(first.status, 'succeeded');
    const replay = await call(name, apply); assert.equal(replay.replayed, true); assert.deepEqual(replay.result, first.result);
    return first.result;
  };
  const cat = await mutate('catalog_categories_create', { gp_name: 'SDK category' });
  const media = await mutate('catalog_media_upload', { filename: 'synthetic.png', mimeType: 'image/png', contentBase64: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC' });
  assert.equal(media.width, 1); assert.equal(media.height, 1);
  const product = await mutate('catalog_products_create', { group_id: cat.id, gd_name: 'SDK product', gd_description: 'Synthetic description', type: 1, actual_price: '12.34' });
  await mutate('inventory_cards_import', { goods_id: product.id, cards: 'SDK-CARD-A\nSDK-CARD-B' });
  const cards = await call('inventory_cards_list', { goods_id: product.id }); assert.equal(cards.total, 2);
  await mutate('inventory_cards_update', { id: cards.items[0].id, carmi: 'SDK-CARD-CHANGED' });
  assert.equal((await call('inventory_cards_content', { id: cards.items[0].id })).carmi, 'SDK-CARD-CHANGED');
  await mutate('coupons_create', { coupon: 'SDK-COUPON', discount: '1.00', goods: [product.id], ret: 2, is_open: true });
  await mutate('orders_complete', { id: 1, message: 'Synthetic completed', notifyCustomer: false });
  await mutate('payments_update', { id: 14, pay_name: 'SDK gateway' });
  const refund = await mutate('refunds_record_external', { orderId: 1, amount: '1.00', currency: 'CNY', provider: 'manual', externalReference: 'SDK-EXTERNAL-REFUND', refundedAt: new Date(Date.now() - 60000).toISOString().replace(/\.\d{3}Z$/, 'Z') });
  await mutate('settings_update', { values: { title: 'SDK shop', password: 'SYNTHETIC-WRITE-ONLY' } });
  assert.ok(!JSON.stringify(await call('settings_get')).includes('SYNTHETIC-WRITE-ONLY'));
  await mutate('email_templates_create', { tpl_name: 'SDK template', tpl_token: 'sdk_template', tpl_content: '<p>Untrusted template</p>' });
  // mcp-bootstrap.php seeds this database-local date, not the current UTC date.
  const date = '2026-01-15';
  const totals = await call('operations_sales_summary', { from: date, until: date }); assert.equal(totals.completedOrders, 1);
  const outside = await call('operations_sales_summary', { from: '2026-01-16', until: '2026-01-16' }); assert.equal(outside.completedOrders, 0);
  assert.ok((await call('operations_audit')).total > 0);
  assert.equal((await call('refunds_capabilities')).cashRefundSupported, false);
  for (const name of ['catalog_products_list', 'catalog_categories_list', 'coupons_list', 'orders_list', 'orders_status_counts', 'payments_list', 'refunds_list', 'email_templates_list', 'operations_low_stock', 'operations_failed_jobs', 'operations_queue_status', 'settings_turnstile_status']) await call(name);
  for (const [name, args] of [
    ['catalog_products_get', { id: product.id }], ['catalog_categories_get', { id: cat.id }],
    ['inventory_cards_get', { id: cards.items[0].id }], ['inventory_cards_export', { ids: cards.items.map(c => c.id) }],
    ['coupons_get', { id: 1 }], ['orders_get', { id: 1 }], ['orders_content', { id: 1 }],
    ['payments_get', { id: 14 }], ['refunds_get', { id: refund.id }], ['email_templates_get', { id: 1 }],
    ['operations_receipt', { id: 1 }],
  ]) await call(name, args);
  console.log(JSON.stringify({ sdk: 'official-typescript', protocol: 'StreamableHTTP', tools: tools.length, lifecycles: sequence, unauthenticatedStatus: 401, result: 'passed' }));
} finally { await client.close(); }
