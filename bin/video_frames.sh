#!/bin/sh
# Frames and sound of a video, in the sandbox as the participant: up to N frames evenly over the video (JPEG, 768 px
# wide) and the sound as audio.m4a (none when the video has no sound). For a video question when no video model is
# set: the frames go to a vision model, the sound to a speech model.
#     sh video_frames.sh <video> <folder> [frames]
# spec: BRIEF.md#входящие-вложения — video: a video model, else frames and speech
set -eu
in=$1
out=$2
n=${3:-8}
mkdir -p "$out"
seconds=$(ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "$in" 2>/dev/null | cut -d. -f1)
case "$seconds" in ''|*[!0-9]*) seconds=1 ;; esac
[ "$seconds" -lt 1 ] && seconds=1
ffmpeg -nostdin -v error -y -i "$in" -vf "fps=$n/$seconds,scale=768:-2" -frames:v "$n" "$out/frame-%02d.jpg"
ffmpeg -nostdin -v error -y -i "$in" -vn -ac 1 -ar 16000 -b:a 48k "$out/audio.m4a" 2>/dev/null || rm -f "$out/audio.m4a"
ls "$out"
