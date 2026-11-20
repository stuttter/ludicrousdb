# HyperDB upstream parity

LudicrousDB began as a distribution of Automattic's
[HyperDB](https://github.com/Automattic/HyperDB) database drop-in. The projects
have since diverged, but HyperDB remains an important source of database
routing and failover fixes.

The repositories do not have a usable shared Git merge base. HyperDB's current
Git history begins with a 2021 import, while LudicrousDB's history begins in
2014. Upstream changes therefore need a behavioral review rather than a blind
merge.

## Review policy

For every new commit on HyperDB's `trunk` branch:

1. Compare the changed behavior with the current LudicrousDB implementation.
2. Classify it as already present, safe to port, intentionally divergent,
   obsolete, or requiring a compatibility decision.
3. Add characterization coverage before changing routing, connection,
   failover, replication-lag, or query behavior.
4. Preserve LudicrousDB's published PHP 7.4 and WordPress 6.4 minimums.
5. Prefer LudicrousDB's modern MySQLi, object-cache, testing, and CI designs.
   Do not restore HyperDB's obsolete `mysql_*` compatibility layer.

## Audited baseline

Last reviewed upstream commit:
[`082a127`](https://github.com/Automattic/HyperDB/commit/082a12756d8699059f95b8152e4d63f68caa1b22)
(November 13, 2026).

| Upstream work | LudicrousDB disposition |
| --- | --- |
| Preserve query errors and guard `FOUND_ROWS()` after a failed query ([`978fbf9`](https://github.com/Automattic/HyperDB/commit/978fbf9c1bad40eee3c80e8c514e9cf5731a3989)) | Already present through LudicrousDB's connection-error and `FOUND_ROWS()` fixes. |
| Allow PHP 8.2 dynamic properties ([`e23ea7b`](https://github.com/Automattic/HyperDB/commit/e23ea7bf9f003815ab1c750380550b0d97a37be4)) | Intentionally superseded. LudicrousDB declares the compatibility properties instead of allowing arbitrary dynamic properties. |
| Replace the deprecated `mysqli_ping()` path, culminating in an active `DO 1` probe ([`6f36eb5`](https://github.com/Automattic/HyperDB/commit/6f36eb5fb20c18093ccd257dbd5c9f401e6b3cf0)) | Ported in LudicrousDB form. Failed probes remove the stale handle before reconnecting. |
| Disconnect a handle after MySQL server-gone errors ([`8a51769`](https://github.com/Automattic/HyperDB/commit/8a51769dec24eed55e59e3b8afd251311efd49b6)) | Superseded upstream by the active-probe change. LudicrousDB performs stale-handle removal in its connection check. |
| Route locking reads to writable servers ([`082a127`](https://github.com/Automattic/HyperDB/commit/082a12756d8699059f95b8152e4d63f68caa1b22)) | Ported conservatively in LudicrousDB form. `FOR UPDATE`, `FOR SHARE`, and `LOCK IN SHARE MODE` use writable servers; ambiguous comment syntax routes writable without upstream's last-quote shortcut. |

Future reviews should update the baseline commit and append any disposition that
is not self-evident from the LudicrousDB commit history.
