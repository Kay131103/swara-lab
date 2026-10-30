"""Private Vercel Blob operations for PHP request handlers."""

import argparse
import asyncio
import json
import os
from pathlib import Path
import shutil
import sys


async def run(args):
    if os.environ.get("BLOB_STORAGE_MODE", "vercel") == "local":
        root = Path(os.environ.get("LOCAL_STORAGE_DIR", "/tmp/melomaster-storage")).resolve()
        destination = (root / args.pathname).resolve()
        if root not in destination.parents:
            raise ValueError("Invalid storage pathname.")
        if args.operation == "put":
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(args.file, destination)
            return {"pathname": args.pathname}
        if args.operation == "get":
            destination.resolve(strict=True)
            Path(args.file).write_bytes(destination.read_bytes())
            return {"bytes": destination.stat().st_size}
        destination.unlink(missing_ok=True)
        return {"deleted": True}

    from vercel import blob

    if args.operation == "put":
        result = await blob.put_blob(
            args.pathname,
            Path(args.file).read_bytes(),
            access="private",
            content_type=args.content_type,
        )
        return {"pathname": result.pathname}
    if args.operation == "get":
        result = await blob.get_blob(args.pathname, access="private")
        Path(args.file).write_bytes(result.body)
        return {"bytes": len(result.body)}
    await blob.delete_blob(args.pathname)
    return {"deleted": True}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("operation", choices=["put", "get", "delete"])
    parser.add_argument("pathname")
    parser.add_argument("--file")
    parser.add_argument("--content-type", default="application/octet-stream")
    args = parser.parse_args(argv)
    if args.operation in {"put", "get"} and not args.file:
        parser.error("--file is required for put and get operations")
    try:
        result = asyncio.run(run(args))
    except Exception as error:
        print(str(error), file=sys.stderr)
        return 1
    print(json.dumps(result, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
