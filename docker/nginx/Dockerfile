FROM nginx:alpine

# Install curl for healthcheck
RUN apk add --no-cache curl

COPY docker/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
