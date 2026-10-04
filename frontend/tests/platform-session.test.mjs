import assert from 'node:assert/strict'
import test from 'node:test'
import { QueryClient } from '@tanstack/react-query'
import { authenticatePlatformSession } from '../src/features/platform/authenticatePlatformSession.ts'

test('an old unauthorized session response cannot overwrite a verified MFA login', async () => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const key = ['platform', 'me']
  let rejectOldRequest
  const oldRequest = client.fetchQuery({
    queryKey: key,
    queryFn: () => new Promise((_, reject) => { rejectOldRequest = reject }),
  }).catch(() => undefined)
  const user = { ulid: 'verified-admin', must_change_password: false }

  const result = await authenticatePlatformSession(client, async () => {
    assert.equal(client.getQueryState(key).fetchStatus, 'idle')
    return user
  }, async () => user)
  rejectOldRequest(new Error('401 from before MFA verification'))
  await oldRequest
  await new Promise((resolve) => setImmediate(resolve))

  assert.equal(result, user)
  assert.equal(client.getQueryData(key), user)
  assert.equal(client.getQueryState(key).error, null)
  client.clear()
})

test('a rejected MFA code never checks or establishes an authenticated session', async () => {
  const client = new QueryClient()
  let checkedSession = false
  await assert.rejects(authenticatePlatformSession(client, async () => {
    throw new Error('Invalid verification code')
  }, async () => { checkedSession = true }), /Invalid verification code/)
  assert.equal(checkedSession, false)
  assert.equal(client.getQueryData(['platform', 'me']), undefined)
  client.clear()
})

test('a successful MFA response without a working session does not allow navigation', async () => {
  const client = new QueryClient()
  await assert.rejects(authenticatePlatformSession(client,
    async () => ({ ulid: 'verified-admin' }),
    async () => { throw new Error('Session cookie was not accepted') },
  ), /Session cookie was not accepted/)
  assert.equal(client.getQueryData(['platform', 'me']), undefined)
  client.clear()
})
