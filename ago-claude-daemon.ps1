# ============================================================
# AGO Claude Code デスクトップ常駐デーモン
# LINE からの指示を受け取り Claude Code に実行させてLINEに返す
# ============================================================

$KV_URL           = "https://system002-od.ordermade-neon.com/api.php"
$LINE_PUSH_URL    = "https://system002-od.ordermade-neon.com/line-push.php"
$KV_TOKEN         = "system002-od"
$AGO_DIR          = "C:\Users\Administrator\Desktop\AI版AGO"
$LOG_FILE         = "$AGO_DIR\ago-daemon.log"
$POLL_SEC         = 5
$TASK_TIMEOUT     = 600   # claude の最大実行秒数

$LW_SCRIPT   = "$AGO_DIR\lineworks_send.py"
$LW_ROOM_MAP = "$AGO_DIR\lineworks-room-map.json"

# ── ログ出力 ─────────────────────────────────────────────────
function Write-Log($msg) {
    $line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') $msg"
    Write-Host $line
    Add-Content -Path $LOG_FILE -Value $line -Encoding UTF8 -ErrorAction SilentlyContinue
}

# ── KV 全件取得 ───────────────────────────────────────────────
function Invoke-KVGetAll {
    $body = [System.Text.Encoding]::UTF8.GetBytes('{"action":"kv_getAll"}')
    $res = Invoke-RestMethod -Uri $KV_URL -Method POST `
        -ContentType "application/json; charset=utf-8" `
        -Headers @{"X-AGO-Token" = $KV_TOKEN} `
        -Body $body -TimeoutSec 10
    return $res.data
}

# ── KV 書き込み ───────────────────────────────────────────────
function Invoke-KVSet($key, $value) {
    $obj  = @{action = "kv_set"; key = $key; value = $value}
    $body = [System.Text.Encoding]::UTF8.GetBytes(($obj | ConvertTo-Json -Compress))
    Invoke-RestMethod -Uri $KV_URL -Method POST `
        -ContentType "application/json; charset=utf-8" `
        -Headers @{"X-AGO-Token" = $KV_TOKEN} `
        -Body $body -TimeoutSec 30 | Out-Null
}

# PowerShell 5.1 で1件でも必ず配列JSONにする
function ConvertTo-JsonArray($items) {
    if ($null -eq $items -or $items.Count -eq 0) { return "[]" }
    $parts = @($items | ForEach-Object { $_ | ConvertTo-Json -Compress -Depth 10 })
    return "[" + ($parts -join ",") + "]"
}

# ── LINE WORKS Playwright 送信 ────────────────────────────────
function Send-LineWorksMessage($targetId, $message) {
    if (-not $targetId) { Write-Log "[LW] targetId empty, skip"; return }

    # ルームマップを読み込む
    $roomName = $null
    if (Test-Path $LW_ROOM_MAP) {
        $map = Get-Content $LW_ROOM_MAP -Raw -Encoding UTF8 | ConvertFrom-Json
        $roomName = $map.$targetId
    }

    if (-not $roomName) {
        Write-Log "[LW] ルームマップ未登録: $targetId → KVフォールバック"
        Invoke-KVSet "ago_pending_notify_$targetId" $message
        return
    }

    # メッセージをtempファイル経由で渡す（日本語文字化け対策）
    $tmpFile = [System.IO.Path]::GetTempFileName() -replace '\.tmp$', '.txt'
    [System.IO.File]::WriteAllText($tmpFile, $message, [System.Text.Encoding]::UTF8)

    try {
        Write-Log "[LW] 送信開始 room=$roomName"
        $output = & python $LW_SCRIPT $roomName $tmpFile --headless 2>&1
        Write-Log "[LW] $output"
        Write-Log "[LW] 送信完了 to=$roomName"
    } catch {
        Write-Log "[LW] 送信エラー: $_ → KVフォールバック"
        Invoke-KVSet "ago_pending_notify_$targetId" $message
    } finally {
        Remove-Item $tmpFile -ErrorAction SilentlyContinue
    }
}

# ── Claude Code 実行（タイムアウト付き） ───────────────────────
function Invoke-Claude($prompt) {
    $tmpOut = [System.IO.Path]::GetTempFileName()
    $tmpErr = [System.IO.Path]::GetTempFileName()
    try {
        $psi = New-Object System.Diagnostics.ProcessStartInfo
        $psi.FileName               = "claude"
        $psi.Arguments              = "-p `"$($prompt.Replace('"','\"'))`""
        $psi.WorkingDirectory       = $AGO_DIR
        $psi.UseShellExecute        = $false
        $psi.RedirectStandardOutput = $true
        $psi.RedirectStandardError  = $true
        $psi.StandardOutputEncoding = [System.Text.Encoding]::UTF8

        $proc = [System.Diagnostics.Process]::Start($psi)
        $outTask = $proc.StandardOutput.ReadToEndAsync()
        $errTask = $proc.StandardError.ReadToEndAsync()

        $finished = $proc.WaitForExit($TASK_TIMEOUT * 1000)
        if (-not $finished) {
            $proc.Kill()
            return @{ok=$false; text="タイムアウト（${TASK_TIMEOUT}秒）"}
        }
        [System.Threading.Tasks.Task]::WaitAll($outTask, $errTask)
        $out = $outTask.Result.Trim()
        return @{ok=$true; text=if ($out) {$out} else {"（出力なし）"}}
    } catch {
        return @{ok=$false; text="実行エラー: $_"}
    }
}

# ── メインループ ──────────────────────────────────────────────
Write-Log "[START] AGO Claude Daemon 起動  poll=${POLL_SEC}s  timeout=${TASK_TIMEOUT}s"
Write-Log "[INFO]  通知方式=WebPush+管理マネ"

while ($true) {
    Start-Sleep -Seconds $POLL_SEC
    try {
        $kv = Invoke-KVGetAll
        if (-not $kv) { continue }

        $queueRaw = $kv."ago_claude_queue"
        if (-not $queueRaw -or $queueRaw -eq "[]") { continue }

        # JSON パース
        $queue = $queueRaw | ConvertFrom-Json
        if ($queue -isnot [System.Collections.IEnumerable]) { $queue = @($queue) }
        $queue = [System.Collections.ArrayList]@($queue)

        $pending = @($queue | Where-Object { $_.status -eq "pending" })
        if ($pending.Count -eq 0) { continue }

        $task = $pending[0]
        Write-Log "[TASK] id=$($task.id)  from=$($task.requester_name)"
        Write-Log "[TASK] prompt=$($task.prompt.Substring(0, [Math]::Min(80, $task.prompt.Length)))"

        # processing に変更
        ($queue | Where-Object { $_.id -eq $task.id }).status = "processing"
        Invoke-KVSet "ago_claude_queue" (ConvertTo-JsonArray $queue)

        # Claude Code 実行
        $toolsPrompt = ""
        $toolsFile = "$AGO_DIR\claude-tools-prompt.txt"
        if (Test-Path $toolsFile) {
            $toolsPrompt = Get-Content $toolsFile -Raw -Encoding UTF8
        }
        $fullPrompt = @"
【LINEからの業務指示】
送信者: $($task.requester_name)
指示内容: $($task.prompt)

上記の指示を実行してください。必要に応じて配下AIに委託し、結果を日本語で簡潔に報告してください。

$toolsPrompt
"@
        Write-Log "[CLAUDE] running..."
        $claudeResult = Invoke-Claude $fullPrompt
        Write-Log "[CLAUDE] ok=$($claudeResult.ok)  len=$($claudeResult.text.Length)"

        # 結果を KV に保存（失敗してもLINE通知は続行）
        try {
            # キューを最新状態で再取得してから更新（Claude実行中の変更を上書きしないため）
            $freshKv = Invoke-KVGetAll
            $freshQueueRaw = $freshKv."ago_claude_queue"
            $freshQueue = $freshQueueRaw | ConvertFrom-Json
            if ($freshQueue -isnot [System.Collections.IEnumerable]) { $freshQueue = @($freshQueue) }
            $freshQueue = [System.Collections.ArrayList]@($freshQueue)

            $target = $freshQueue | Where-Object { $_.id -eq $task.id }
            $target.status = if ($claudeResult.ok) { "done" } else { "error" }
            $target | Add-Member -Force -NotePropertyName result `
                -NotePropertyValue ($claudeResult.text.Substring(0, [Math]::Min(500, $claudeResult.text.Length)))
            $target | Add-Member -Force -NotePropertyName completed_at `
                -NotePropertyValue (Get-Date -Format "yyyy-MM-dd HH:mm:ss")

            # 24時間以上前の完了タスクを削除
            $cutoff = (Get-Date).AddHours(-24)
            $freshQueue = [System.Collections.ArrayList]@(
                $freshQueue | Where-Object {
                    $_.status -notin @("done","error") -or
                    -not $_.completed_at -or
                    [DateTime]::Parse($_.completed_at) -gt $cutoff
                }
            )
            Invoke-KVSet "ago_claude_queue" (ConvertTo-JsonArray $freshQueue)
            Write-Log "[KV] queue saved ok"

            # ago_line_logs を更新（管理マネに反映）
            try {
                $logsRaw = $freshKv."ago_line_logs"
                if ($logsRaw) {
                    $logs = $logsRaw | ConvertFrom-Json
                    if ($logs -isnot [System.Collections.IEnumerable]) { $logs = @($logs) }
                    $logs = [System.Collections.ArrayList]@($logs)

                    $resultText = $claudeResult.text.Substring(0, [Math]::Min(200, $claudeResult.text.Length))
                    $logUpdated = $false

                    if ($task.log_id) {
                        # log_id で直接特定して更新
                        $logEntry = $logs | Where-Object { $_.id -eq $task.log_id }
                        if ($logEntry) {
                            $logEntry.status = "replied"
                            $logEntry | Add-Member -Force -NotePropertyName ai_reply -NotePropertyValue $resultText
                            $logUpdated = $true
                            Write-Log "[LOG] log updated id=$($task.log_id)"
                        } else {
                            Write-Log "[LOG] log not found id=$($task.log_id)"
                        }
                    } else {
                        # log_id なし: requester_id で最新の processing ログを探す
                        $uid = $task.requester_id
                        if ($uid) {
                            $bestLog = $null
                            $bestTs  = ""
                            foreach ($log in $logs) {
                                if (-not $log.userId -or $log.userId -ne $uid) { continue }
                                if (-not $log.status -or $log.status -ne "processing") { continue }
                                if (-not $bestLog -or ($log.ts -gt $bestTs)) {
                                    $bestLog = $log
                                    $bestTs  = $log.ts
                                }
                            }
                            if ($bestLog) {
                                $bestLog.status = "replied"
                                $bestLog | Add-Member -Force -NotePropertyName ai_reply -NotePropertyValue $resultText
                                $logUpdated = $true
                                Write-Log "[LOG] fallback log updated userId=$uid id=$($bestLog.id)"
                            } else {
                                Write-Log "[LOG] fallback: processing log not found userId=$uid"
                            }
                        } else {
                            Write-Log "[LOG] log_id and requester_id both empty, skip"
                        }
                    }

                    if ($logUpdated) {
                        Invoke-KVSet "ago_line_logs" (ConvertTo-JsonArray $logs)
                        Write-Log "[LOG] ago_line_logs saved ok"
                    }
                }
            } catch {
                Write-Log "[LOG_ERR] log update failed: $_"
            }

            Start-Sleep -Seconds 3

            # LINE グループへ完了通知（200通上限のため届かない場合あり・来たらラッキー）
            $requester_id = $task.requester_id
            if ($requester_id) {
                $line_result_text = $claudeResult.text.Substring(0, [Math]::Min(300, $claudeResult.text.Length))
                $line_msg = "✅ 作業完了（$((Get-Date -Format 'HH:mm'))完了）`n`n$line_result_text"
                $line_body = [System.Text.Encoding]::UTF8.GetBytes(
                    (@{to = $requester_id; message = $line_msg} | ConvertTo-Json -Compress)
                )
                try {
                    Invoke-RestMethod -Uri $LINE_PUSH_URL -Method POST `
                        -ContentType "application/json; charset=utf-8" `
                        -Headers @{"X-AGO-Token" = $KV_TOKEN} `
                        -Body $line_body -TimeoutSec 10 | Out-Null
                    Write-Log "[LINE] グループ通知送信 to=$requester_id"
                } catch {
                    Write-Log "[LINE] 送信失敗（200通上限の可能性）: $($_.Exception.Message)"
                }
            }

            # WebPush を即時送信（クロン3分待ちをなくす）
            try {
                Invoke-WebRequest -Uri "https://system002-od.ordermade-neon.com/notify-completed.php" -Method GET -UseBasicParsing -TimeoutSec 30 | Out-Null
                Write-Log "[NOTIFY] 完了通知を即時送信"
            } catch {
                Write-Log "[NOTIFY_ERR] 完了通知送信失敗: $_"
            }
        } catch {
            Write-Log "[KV] save error: $_"
        }

        # LINE WORKS への返信は廃止済み（WebPush + 管理マネで通知）
        # Send-LineWorksMessage $task.requester_id $lineMsg

    } catch {
        Write-Log "[ERR] $_"
    }
}
