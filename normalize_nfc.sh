#!/bin/bash

# Recursively find all files and directories
find . -depth -print0 | while IFS= read -r -d '' file; do
    # Convert the filename from macOS NFD to Linux NFC
    new_name=$(echo "$file" | iconv -f UTF-8-MAC -t UTF-8)

    # Only rename if the filename has changed
    if [[ "$file" != "$new_name" ]]; then
        mv -v "$file" "$new_name"
    fi
done

echo "All filenames have been converted to NFC."