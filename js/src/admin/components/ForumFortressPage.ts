import ExtensionPage, {
  ExtensionPageAttrs,
} from "flarum/admin/components/ExtensionPage";
import type Mithril from "mithril";
import ForumFortressControls from "./ForumFortressControls";

export default class ForumFortressPage extends ExtensionPage {
  content(vnode: Mithril.VnodeDOM<ExtensionPageAttrs, this>): Mithril.Vnode {
    return m(".ForumFortressExtensionPage", [
      m(".ForumFortressDashboard", m(".container", m(ForumFortressControls))),
      super.content(vnode),
    ]);
  }
}
