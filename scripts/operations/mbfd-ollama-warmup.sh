#!/usr/bin/env bash
set -Eeuo pipefail

readonly OLLAMA_URL="${OLLAMA_URL:-http://127.0.0.1:11434}"
readonly OLLAMA_MODEL="${OLLAMA_MODEL:-qwen3.6:35b}"

for attempt in $(seq 1 90); do
    if curl --fail --silent --show-error --max-time 2 \
        "${OLLAMA_URL}/api/version" >/dev/null; then
        break
    fi

    if (( attempt == 90 )); then
        echo "Ollama API did not become ready within 180 seconds" >&2
        exit 1
    fi

    sleep 2
done

payload=$(printf '{"model":"%s","messages":[{"role":"user","content":"Reply with ready."}],"stream":false,"keep_alive":-1,"think":false,"options":{"num_predict":8}}' "${OLLAMA_MODEL}")

response=$(curl --fail --silent --show-error \
    --connect-timeout 3 \
    --max-time 600 \
    --header 'Content-Type: application/json' \
    --data-binary "${payload}" \
    "${OLLAMA_URL}/api/chat")

if ! jq -e --arg model "${OLLAMA_MODEL}" \
    '.done == true and .model == $model and (.message.content | type == "string")' \
    >/dev/null <<<"${response}"; then
    echo "Ollama warmup returned an invalid response" >&2
    exit 1
fi

echo "ollama_warmup=pass model=${OLLAMA_MODEL}"
