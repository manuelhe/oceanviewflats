#!/bin/bash

# Exit on error
set -e

echo "Starting image thumbnail optimization..."

# Setup directories
mkdir -p public/images/1707/thumbs
mkdir -p public/images/1606/thumbs

MAGICK_PATH="/opt/homebrew/bin/magick"

# Fallback to standard "magick" or "convert" if homebrew path isn't present
if [ ! -f "$MAGICK_PATH" ]; then
  if command -v magick &> /dev/null; then
    MAGICK_PATH="magick"
  elif command -v convert &> /dev/null; then
    MAGICK_PATH="convert"
  else
    echo "Error: ImageMagick (magick or convert) not found in PATH or at $MAGICK_PATH"
    exit 1
  fi
fi

echo "Using ImageMagick command: $MAGICK_PATH"

# Convert 1707 images
for img in public/images/1707/*.webp; do
  # Avoid reprocessing existing thumbnails if script is re-run
  if [[ "$img" == *"thumbs"* ]]; then
    continue
  fi
  basename=$(basename "$img")
  echo "Generating thumbnail for 1707/$basename..."
  "$MAGICK_PATH" "$img" -resize 480x -quality 75 "public/images/1707/thumbs/$basename"
done

# Convert 1606 images
for img in public/images/1606/*.webp; do
  if [[ "$img" == *"thumbs"* ]]; then
    continue
  fi
  basename=$(basename "$img")
  echo "Generating thumbnail for 1606/$basename..."
  "$MAGICK_PATH" "$img" -resize 480x -quality 75 "public/images/1606/thumbs/$basename"
done

echo "Thumbnail generation complete!"
