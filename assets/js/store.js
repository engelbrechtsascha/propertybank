// FINATUM PropertyBank — what the browser holds: the signed-in person, every
// listing, the cities and the refresh status. Filtering and sorting run on
// this copy; the server is the record.

import { api } from "./api.js";

export const store = {
  me: null,
  user: null,
  prefs: {},
  listings: null,          // all listings, removed ones included once asked for
  withRemoved: false,
  cities: [],
  status: null,
  marked: new Set(),     // ads marked as of interest, as "source:id"

  reset() {
    this.user = null; this.prefs = {}; this.listings = null; this.cities = []; this.status = null; this.withRemoved = false;
  },

  async load(withRemoved = this.withRemoved) {
    const r = await api("listings", { params: { all: withRemoved ? "1" : "" } });
    this.listings = r.listings;
    this.cities = r.cities;
    this.status = r.status;
    this.marked = new Set(r.marked || []);
    this.withRemoved = withRemoved;
    return this;
  },

  city(id) {
    return this.cities.find((c) => c.id === id) || null;
  },
};
