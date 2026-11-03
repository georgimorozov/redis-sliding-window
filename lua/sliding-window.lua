-- redis-sliding-window: one atomic sliding-window (sliding log) check. Contract: docs/design.md.
-- KEYS[1] bucket key; ARGV[1] limit; ARGV[2] windowMs; ARGV[3] nonce. The callers validate the inputs.
-- Returns {allowed, remaining, retryAfterMs, resetAfterMs}.
local key = KEYS[1]
local limit = tonumber(ARGV[1])
local window_ms = tonumber(ARGV[2])
local nonce = ARGV[3]

local time = redis.call('TIME')
local now = tonumber(time[1]) * 1000000 + tonumber(time[2])
local window_us = window_ms * 1000

-- Integers go to commands as exact decimal strings: Lua's default number formatting rounds.
local function int(n)
  return string.format('%d', n)
end

-- Milliseconds until the entry scored `score` ages out, clamped to 1..windowMs.
local function until_aged_out(score)
  local ms = math.ceil((score + window_us - now) / 1000)
  if ms < 1 then return 1 end
  if ms > window_ms then return window_ms end
  return ms
end

local function score_at(rank)
  local entry = redis.call('ZRANGE', key, rank, rank, 'WITHSCORES')
  return tonumber(entry[2])
end

redis.call('ZREMRANGEBYSCORE', key, '-inf', int(now - window_us))
local count = redis.call('ZCARD', key)

if redis.call('ZSCORE', key, nonce) then
  local remaining = limit - count
  if remaining < 0 then remaining = 0 end
  return {1, remaining, 0, until_aged_out(score_at(-1))}
end

if count < limit then
  redis.call('ZADD', key, int(now), nonce)
  redis.call('PEXPIRE', key, ARGV[2])
  return {1, limit - count - 1, 0, window_ms}
end

return {0, 0, until_aged_out(score_at(count - limit)), until_aged_out(score_at(-1))}
